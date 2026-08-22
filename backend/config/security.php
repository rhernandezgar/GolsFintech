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
    | El algoritmo por defecto es SHA-256 y no el SHA-1 habitual. No porque
    | HMAC-SHA-1 sea inseguro —no lo es: los ataques de colision contra SHA-1 no
    | se trasladan a HMAC, CLAUDE.md 6.3—, sino por higiene: no dejar el literal
    | `sha1` en el arbol. El coste es de compatibilidad con los autenticadores
    | que ignoran el parametro algorithm del URI otpauth, Google Authenticator
    | entre ellos. CLAUDE.md 6.4 explica cuando hay que reevaluar esto.
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

    /*
    |---------------------------------------------------------------------------
    | Sesion del prospecto (P1)
    |---------------------------------------------------------------------------
    |
    | El visitante que empieza una solicitud no tiene cuenta: el token se emite
    | en P1, al crear el expediente. Vigencia corta y renovacion silenciosa
    | mientras haya actividad, no vigencia larga: la Fase 1 estima el tramite
    | completo en menos de 5 minutos (RNF-02), asi que 30 minutos sobran para
    | terminarlo y acotan la ventana si el token se filtra.
    |
    | La renovacion emite un token nuevo y NO revoca el anterior: revocarlo
    | dejaria sin credencial a las peticiones ya en vuelo —la consulta de
    | estado del OCR de P3 va en bucle— y el anterior caduca solo dentro de su
    | propia ventana. El limite de 30 minutos se mantiene para cada token.
    |
    */

    'prospect_session' => [
        'ttl_minutes' => (int) env('PROSPECT_SESSION_TTL', 30),

        // Margen con el que la SPA pide un token nuevo antes de que caduque el
        // suyo. Cinco minutos dan de sobra para reintentar si la red falla.
        'renew_before_seconds' => (int) env('PROSPECT_SESSION_RENEW_BEFORE', 300),
    ],

    /*
    |---------------------------------------------------------------------------
    | Aviso de privacidad
    |---------------------------------------------------------------------------
    |
    | La version del aviso viaja a la bitacora junto con el sello de tiempo y la
    | direccion IP: el consentimiento que exige la LFPDPPP no es un booleano,
    | es la prueba de QUE texto acepto una persona concreta y CUANDO. Si el
    | aviso cambia, esta version cambia con el y los consentimientos anteriores
    | siguen diciendo a que texto se referian.
    |
    */

    'privacy_notice' => [
        'version' => env('PRIVACY_NOTICE_VERSION', '2026-08-01'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Control anti-automatizacion (CAPTCHA)
    |---------------------------------------------------------------------------
    |
    | RS-10 y el riesgo R-05 piden control anti-automatizacion, no solo
    | limitacion de peticiones: throttle limita por direccion IP, y crear
    | expedientes en masa desde direcciones distintas pasa por debajo de ese
    | umbral sin despeinarse.
    |
    | El driver `simulated` NO es "aceptar todo": acepta unicamente el token de
    | prueba configurado y rechaza cualquier otro, de modo que la comprobacion
    | se puede probar sin depender de un tercero. En produccion se define
    | CAPTCHA_DRIVER=turnstile con su secreto.
    |
    */

    'captcha' => [
        'driver' => env('CAPTCHA_DRIVER', 'simulated'),
        'simulated_token' => env('CAPTCHA_SIMULATED_TOKEN', 'captcha-ok'),
        'turnstile' => [
            'secret' => env('TURNSTILE_SECRET'),
            'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
            'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT', 5),
        ],
    ],

    'oauth' => [
        'code_challenge_methods' => ['S256'],
        'access_token_ttl_minutes' => (int) env('OAUTH_ACCESS_TOKEN_TTL', 15),
        'refresh_token_ttl_days' => (int) env('OAUTH_REFRESH_TOKEN_TTL', 14),
    ],

];
