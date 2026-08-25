<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use InvalidArgumentException;

/**
 * Segundo factor basado en codigos temporales (TOTP, RFC 6238).
 *
 * La Fase 3 §4.4 exige TOTP obligatorio para todo perfil administrativo
 * (RS-01). Se implementa aqui, sin dependencia externa, porque el algoritmo es
 * un HMAC con truncamiento dinamico definido en la norma: no hay criptografia
 * propia que inventar, solo RFC 4226 (HOTP) y RFC 6238 (TOTP) aplicados.
 *
 * ATENCION — algoritmo por defecto SHA-256, no SHA-1 (CLAUDE.md 6.4):
 * la implementacion habitual de TOTP usa HMAC-SHA-1. Conviene ser exacto sobre
 * el motivo del cambio: **HMAC-SHA-1 no seria inseguro aqui**. La seguridad de
 * HMAC no descansa en la resistencia a colisiones de la funcion interna, y los
 * ataques conocidos contra SHA-1 no se trasladan a HMAC-SHA-1 (CLAUDE.md 6.3).
 * SHA-256 se elige por higiene —no dejar el literal `sha1` en el arbol—, no
 * para corregir una debilidad. RFC 6238 §1.2 lo contempla expresamente, y el
 * parametro `algorithm=SHA256` del URI otpauth:// lo transmite al autenticador.
 *
 * El coste es de compatibilidad y es serio: muchas aplicaciones —Google
 * Authenticator entre ellas— ignoran ese parametro y calculan siempre con
 * SHA-1, de modo que mostrarian codigos que este servidor rechaza sin ningun
 * mensaje que lo explique. Aegis, FreeOTP y 1Password si lo respetan. Por eso
 * el algoritmo vive en configuracion: si el proyecto llega a tener usuarios
 * reales hay que reevaluar la decision, porque un 2FA que la mayoria no puede
 * usar empuja a desactivarlo. Cambiarlo invalida los secretos ya emitidos.
 */
final class TotpAuthenticator
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly string $algorithm = 'sha256',
        private readonly int $digits = 6,
        private readonly int $period = 30,
        /**
         * Ventana de tolerancia en pasos hacia atras y hacia adelante. Con 1 se
         * aceptan los 30 s anteriores y los 30 s siguientes, que absorben el
         * desfase de reloj del telefono sin ampliar de forma apreciable la
         * ventana de un codigo robado.
         */
        private readonly int $window = 1,
    ) {
        if ($this->digits < 6 || $this->digits > 10) {
            throw new InvalidArgumentException('TOTP digits must be between 6 and 10.');
        }

        if ($this->period < 1) {
            throw new InvalidArgumentException('TOTP period must be positive.');
        }

        if ($this->window < 0) {
            throw new InvalidArgumentException('TOTP window cannot be negative.');
        }
    }

    /**
     * Secreto compartido nuevo, en base32 y con 160 bits de entropia, que es el
     * minimo que recomienda RFC 4226 §4.
     */
    public function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('TOTP secret must be at least 128 bits.');
        }

        return self::encodeBase32(random_bytes($bytes));
    }

    /**
     * Codigo valido en un instante dado.
     */
    public function codeAt(string $base32Secret, int $timestamp): string
    {
        return $this->hotp(self::decodeBase32($base32Secret), intdiv($timestamp, $this->period));
    }

    /**
     * Comprueba el codigo dentro de la ventana de tolerancia.
     *
     * La comparacion es en tiempo constante: un `===` sobre cadenas permite
     * medir cuantos caracteres coinciden y reducir la busqueda a un digito por
     * intento.
     */
    public function verify(string $base32Secret, string $code, ?int $timestamp = null): bool
    {
        $code = trim($code);

        if (! preg_match('/^[0-9]{'.$this->digits.'}$/', $code)) {
            return false;
        }

        $secret = self::decodeBase32($base32Secret);
        $counter = intdiv($timestamp ?? time(), $this->period);

        $valid = false;

        // Se recorre la ventana completa sin cortar al primer acierto para que
        // el tiempo de respuesta no revele en que paso estaba el codigo.
        for ($offset = -$this->window; $offset <= $this->window; $offset++) {
            if (hash_equals($this->hotp($secret, $counter + $offset), $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * URI otpauth:// para el codigo QR de alta del autenticador.
     *
     * No lleva el secreto en ningun registro: quien la genera la entrega al
     * usuario y no la persiste.
     */
    public function provisioningUri(string $base32Secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ]);
    }

    /**
     * HOTP (RFC 4226 §5.3): HMAC del contador en 8 bytes big-endian, seguido
     * del truncamiento dinamico.
     */
    private function hotp(string $rawSecret, int $counter): string
    {
        // El contador puede ser negativo al recorrer la ventana hacia atras en
        // instantes muy proximos al epoch; se trata como 0.
        $counter = max(0, $counter);

        $hash = hash_hmac($this->algorithm, pack('J', $counter), $rawSecret, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 de RFC 4648, que es la codificacion que esperan los autenticadores.
     */
    public static function encodeBase32(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            // `bindec` devuelve float cuando el numero no cabe en un entero, asi
            // que su tipo declarado es `float|int` y un indice flotante no es un
            // indice valido de cadena. Aqui el fragmento es de 5 bits —maximo
            // 31— y nunca desborda, pero esto es codigo de generacion de
            // secretos: la conversion explicita cierra el hueco en el sitio en
            // vez de dejarlo apoyado en el rango del dato (VUL-11).
            $index = (int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT));
            $encoded .= self::BASE32_ALPHABET[$index];
        }

        return str_pad($encoded, (int) (ceil(strlen($encoded) / 8) * 8), '=');
    }

    public static function decodeBase32(string $encoded): string
    {
        $encoded = rtrim(strtoupper(trim($encoded)), '=');

        if ($encoded === '') {
            throw new InvalidArgumentException('Empty TOTP secret.');
        }

        $bits = '';
        foreach (str_split($encoded) as $character) {
            $index = strpos(self::BASE32_ALPHABET, $character);

            if ($index === false) {
                throw new InvalidArgumentException('Malformed base32 TOTP secret.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $raw = '';
        // Los bits sobrantes que no completan un byte son relleno y se descartan.
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $chunk) {
            $raw .= chr(bindec($chunk));
        }

        return $raw;
    }
}
