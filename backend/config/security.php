<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Llave de hash de identificadores personales
    |---------------------------------------------------------------------------
    |
    | Se usa para HMAC-SHA-256 en las columnas curp_hash y rfc_hash, que permiten
    | buscar y detectar duplicados sin descifrar la columna. Con HMAC, obtener una
    | copia de la tabla no basta para revertir los hashes por fuerza bruta.
    |
    | Si no se define PII_HASH_KEY se toma APP_KEY. Conviene definir una llave
    | propia: rotar APP_KEY invalidaria todos los hashes ya calculados.
    |
    */

    'pii_hash_key' => env('PII_HASH_KEY', env('APP_KEY', '')),

    /*
    |---------------------------------------------------------------------------
    | Segundo factor (TOTP, RFC 6238)
    |---------------------------------------------------------------------------
    |
    | El algoritmo por defecto es SHA-256 y no el SHA-1 habitual, porque la
    | regla de seguridad no negociable 6 prohibe SHA-1 para cualquier proposito
    | de seguridad. Vease el comentario de Infrastructure/Security/
    | TotpAuthenticator: el coste es de compatibilidad con los autenticadores
    | que ignoran el parametro algorithm del URI otpauth.
    |
    | La ventana admite un paso hacia atras y otro hacia adelante para absorber
    | el desfase de reloj del telefono. Subirla amplia la vida util de un codigo
    | interceptado y no deberia hacerse sin una razon medida.
    |
    */

    'totp' => [
        'algorithm' => env('TOTP_ALGORITHM', 'sha256'),
        'digits' => (int) env('TOTP_DIGITS', 6),
        'period' => (int) env('TOTP_PERIOD', 30),
        'window' => (int) env('TOTP_WINDOW', 1),
        'issuer' => env('TOTP_ISSUER', 'GolsFintech'),

        // Antiguedad maxima de la ultima verificacion de segundo factor para
        // considerar la sesion reautenticada. La consulta de los datos
        // completos de la tarjeta la exige (Fase 3 §4.9, pantalla P6).
        'reauth_seconds' => (int) env('TOTP_REAUTH_SECONDS', 300),
    ],

    /*
    |---------------------------------------------------------------------------
    | Servidor OAuth2
    |---------------------------------------------------------------------------
    |
    | La SPA de Vue es un cliente publico: no puede custodiar un secreto, porque
    | cualquier valor incorporado en el codigo entregado al navegador es legible
    | por el usuario. Por eso el unico metodo de code_challenge admitido es
    | S256; `plain`, que RFC 7636 permite, deja el verificador a la vista de
    | quien intercepte la peticion de autorizacion y aqui se rechaza.
    |
    */

    'oauth' => [
        'code_challenge_methods' => ['S256'],
        'access_token_ttl_minutes' => (int) env('OAUTH_ACCESS_TOKEN_TTL', 15),
        'refresh_token_ttl_days' => (int) env('OAUTH_REFRESH_TOKEN_TTL', 14),
    ],

];
