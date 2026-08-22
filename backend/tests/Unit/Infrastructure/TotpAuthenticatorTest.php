<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Security\TotpAuthenticator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El segundo factor, contra los vectores de prueba de la norma.
 *
 * Una implementacion de TOTP que "parece funcionar" porque el codigo que genera
 * es el que ella misma verifica no vale de nada: el autenticador del usuario es
 * otro programa. Lo unico que acredita interoperabilidad es reproducir los
 * vectores publicados, y eso es lo que se comprueba aqui.
 */
final class TotpAuthenticatorTest extends TestCase
{
    /** Semilla SHA-256 del apendice B de RFC 6238. */
    private const RFC6238_SHA256_SEED = '12345678901234567890123456789012';

    /** Semilla SHA-512 del apendice B de RFC 6238. */
    private const RFC6238_SHA512_SEED = '1234567890123456789012345678901234567890123456789012345678901234';

    // --- Base32 (RFC 4648) --------------------------------------------------

    /**
     * Los vectores del apendice de RFC 4648. Se comprueban aparte porque si el
     * base32 estuviera mal, los vectores de TOTP fallarian sin que se supiera
     * cual de las dos piezas es la culpable.
     */
    #[DataProvider('base32Vectors')]
    #[Test]
    public function the_base32_encoding_follows_rfc_4648(string $raw, string $encoded): void
    {
        $this->assertSame($encoded, TotpAuthenticator::encodeBase32($raw));
        $this->assertSame($raw, TotpAuthenticator::decodeBase32($encoded));
    }

    /** @return array<string, array{string, string}> */
    public static function base32Vectors(): array
    {
        return [
            'f' => ['f', 'MY======'],
            'fo' => ['fo', 'MZXQ===='],
            'foo' => ['foo', 'MZXW6==='],
            'foob' => ['foob', 'MZXW6YQ='],
            'fooba' => ['fooba', 'MZXW6YTB'],
            'foobar' => ['foobar', 'MZXW6YTBOI======'],
        ];
    }

    #[Test]
    public function the_padding_of_a_base32_secret_is_optional_on_decoding(): void
    {
        $this->assertSame('foo', TotpAuthenticator::decodeBase32('MZXW6'));
        $this->assertSame('foo', TotpAuthenticator::decodeBase32('mzxw6==='));
    }

    #[Test]
    public function a_malformed_base32_secret_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 1, 8 y 9 no pertenecen al alfabeto base32.
        TotpAuthenticator::decodeBase32('MZXW6189');
    }

    // --- TOTP (RFC 6238) ----------------------------------------------------

    #[DataProvider('rfc6238Sha256Vectors')]
    #[Test]
    public function it_reproduces_the_sha256_test_vectors_of_rfc_6238(int $timestamp, string $expected): void
    {
        $authenticator = new TotpAuthenticator(algorithm: 'sha256', digits: 8);
        $secret = TotpAuthenticator::encodeBase32(self::RFC6238_SHA256_SEED);

        $this->assertSame($expected, $authenticator->codeAt($secret, $timestamp));
    }

    /** @return array<string, array{int, string}> */
    public static function rfc6238Sha256Vectors(): array
    {
        return [
            '1970-01-01T00:00:59Z' => [59, '46119246'],
            '2005-03-18T01:58:29Z' => [1111111109, '68084774'],
            '2005-03-18T01:58:31Z' => [1111111111, '67062674'],
            '2009-02-13T23:31:30Z' => [1234567890, '91819424'],
            '2033-05-18T03:33:20Z' => [2000000000, '90698825'],
            '2603-10-11T11:33:20Z' => [20000000000, '77737706'],
        ];
    }

    /**
     * Que el algoritmo sea de verdad configurable: si la clase ignorara el
     * parametro y calculara siempre con SHA-256, estos vectores no saldrian y
     * el `algorithm` de config/security.php seria una mentira.
     *
     * Se comprueba con SHA-512 y no con el SHA-1 del tercer juego de vectores
     * de la norma. No porque usar SHA-1 dentro de HMAC fuese inseguro
     * —no lo es, CLAUDE.md 6.3—, sino porque el literal `sha1` escrito aqui
     * dispararia la verificacion de higiene #2 del script, que no distingue el
     * hash pelado del HMAC. Los vectores SHA-512 acreditan exactamente lo
     * mismo: que el parametro `algorithm` se respeta.
     */
    #[DataProvider('rfc6238Sha512Vectors')]
    #[Test]
    public function the_algorithm_is_really_configurable(int $timestamp, string $expected): void
    {
        $authenticator = new TotpAuthenticator(algorithm: 'sha512', digits: 8);
        $secret = TotpAuthenticator::encodeBase32(self::RFC6238_SHA512_SEED);

        $this->assertSame($expected, $authenticator->codeAt($secret, $timestamp));
    }

    /** @return array<string, array{int, string}> */
    public static function rfc6238Sha512Vectors(): array
    {
        return [
            '1970-01-01T00:00:59Z' => [59, '90693936'],
            '2005-03-18T01:58:29Z' => [1111111109, '25091201'],
            '2009-02-13T23:31:30Z' => [1234567890, '93441116'],
            '2033-05-18T03:33:20Z' => [2000000000, '38618901'],
        ];
    }

    #[Test]
    public function the_default_algorithm_is_sha256(): void
    {
        $secret = TotpAuthenticator::encodeBase32(self::RFC6238_SHA256_SEED);

        // Si el valor por defecto fuese otro algoritmo, este vector SHA-256
        // no saldria.
        $this->assertSame('46119246', (new TotpAuthenticator(digits: 8))->codeAt($secret, 59));
    }

    // --- Verificacion -------------------------------------------------------

    #[Test]
    public function it_accepts_the_code_of_the_current_step(): void
    {
        $authenticator = new TotpAuthenticator;
        $secret = $authenticator->generateSecret();
        $now = 1_700_000_000;

        $this->assertTrue($authenticator->verify($secret, $authenticator->codeAt($secret, $now), $now));
    }

    #[Test]
    public function it_tolerates_one_step_of_clock_drift_in_each_direction(): void
    {
        $authenticator = new TotpAuthenticator(window: 1);
        $secret = $authenticator->generateSecret();
        $now = 1_700_000_000;

        $this->assertTrue($authenticator->verify($secret, $authenticator->codeAt($secret, $now - 30), $now));
        $this->assertTrue($authenticator->verify($secret, $authenticator->codeAt($secret, $now + 30), $now));
    }

    #[Test]
    public function it_rejects_a_code_outside_the_window(): void
    {
        $authenticator = new TotpAuthenticator(window: 1);
        $secret = $authenticator->generateSecret();
        $now = 1_700_000_000;

        $this->assertFalse($authenticator->verify($secret, $authenticator->codeAt($secret, $now - 120), $now));
        $this->assertFalse($authenticator->verify($secret, $authenticator->codeAt($secret, $now + 120), $now));
    }

    #[Test]
    public function it_rejects_the_code_of_another_secret(): void
    {
        $authenticator = new TotpAuthenticator;
        $now = 1_700_000_000;

        $mine = $authenticator->generateSecret();
        $someone_elses = $authenticator->generateSecret();

        $this->assertFalse(
            $authenticator->verify($mine, $authenticator->codeAt($someone_elses, $now), $now)
        );
    }

    /**
     * Nada que no sea exactamente N digitos llega siquiera a compararse. Evita
     * que un `null`, una cadena vacia o un `0` entren como codigo valido por
     * una conversion de tipo.
     */
    #[DataProvider('malformedCodes')]
    #[Test]
    public function it_rejects_a_malformed_code(string $code): void
    {
        $authenticator = new TotpAuthenticator;

        $this->assertFalse($authenticator->verify($authenticator->generateSecret(), $code));
    }

    /** @return array<string, array{string}> */
    public static function malformedCodes(): array
    {
        return [
            'vacio' => [''],
            'demasiado corto' => ['12345'],
            'demasiado largo' => ['1234567'],
            'con letras' => ['12345a'],
            'notacion cientifica' => ['1e5000'],
            'con signo' => ['+12345'],
        ];
    }

    // --- Secreto ------------------------------------------------------------

    #[Test]
    public function the_generated_secret_has_at_least_160_bits(): void
    {
        $secret = (new TotpAuthenticator)->generateSecret();

        $this->assertGreaterThanOrEqual(20, strlen(TotpAuthenticator::decodeBase32($secret)));
    }

    #[Test]
    public function each_generated_secret_is_different(): void
    {
        $authenticator = new TotpAuthenticator;

        $this->assertNotSame($authenticator->generateSecret(), $authenticator->generateSecret());
    }

    #[Test]
    public function a_secret_below_128_bits_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TotpAuthenticator)->generateSecret(bytes: 8);
    }

    #[Test]
    public function the_provisioning_uri_declares_the_algorithm_in_use(): void
    {
        $authenticator = new TotpAuthenticator;
        $secret = $authenticator->generateSecret();

        $uri = $authenticator->provisioningUri($secret, 'auditor@golsfintech.mx', 'GolsFintech');

        $this->assertStringStartsWith('otpauth://totp/GolsFintech:auditor%40golsfintech.mx?', $uri);
        // El parametro es lo unico que puede avisar al autenticador de que no
        // se esta usando SHA-1.
        $this->assertStringContainsString('algorithm=SHA256', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
    }
}
