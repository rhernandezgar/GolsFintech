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
    | Version del contrato de credito aceptada al autorizar en P6. Se guarda
    | en `customers.contract_version` junto con `consent_at` y sirve de
    | soporte probatorio frente a un eventual repudio: si el contrato
    | cambia, esta version cambia con el y los consentimientos anteriores
    | siguen diciendo a que texto se referian.
    */
    'contract' => [
        'version' => env('CONTRACT_VERSION', '2026-08-01'),
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

    /*
    |---------------------------------------------------------------------------
    | Reintento de solicitud por CURP (VUL-17)
    |---------------------------------------------------------------------------
    |
    | La restriccion NO es «una CURP, una solicitud» sino «una CURP, una
    | solicitud ACTIVA a la vez». La primera convierte cualquier intento fallido
    | en un veto permanente: quien abandona a mitad del formulario, o a quien el
    | proveedor de identidad no le confirma los datos una vez, no podria volver a
    | solicitar nunca. La Fase 1 contempla el rechazo por identidad no verificada
    | como un desenlace normal, no como una expulsion.
    |
    | Las dos ventanas miden cosas distintas y por eso son de ordenes distintos:
    |
    |   - `in_progress_window_minutes` libera un FORMULARIO abandonado. Corta,
    |     porque su unico efecto es no dejar fuera a quien de verdad quiere
    |     solicitar. Diez minutos cubren de sobra el tramite, que la Fase 1
    |     estima en menos de cinco (RNF-02).
    |   - `rejected_window_hours` acota los INTENTOS CONTRA UN TERCERO. Larga,
    |     porque lo que limita es el sondeo de INE y RENAPO: sin limite, P4 se
    |     convierte en un oraculo de fuerza bruta con la CURP como unica entrada
    |     (riesgos R-01 y R-05).
    |
    | La expiracion conecta ademas con RS-09 (LFPDPPP, finalidad y
    | proporcionalidad): un expediente abandonado no debe conservarse
    | indefinidamente con datos personales, y marcarlo como abandonado es el
    | primer paso de esa retencion acotada.
    |
    */

    'reapplication' => [
        'in_progress_window_minutes' => (int) env('REAPPLICATION_WINDOW_MINUTES', 10),
        'rejected_window_hours' => (int) env('REAPPLICATION_REJECTED_WINDOW_HOURS', 24),
        'max_rejected_attempts' => (int) env('REAPPLICATION_MAX_REJECTED_ATTEMPTS', 3),
    ],

    /*
    |---------------------------------------------------------------------------
    | Cabeceras de seguridad (T11, VUL-08)
    |---------------------------------------------------------------------------
    |
    | Las aplica `Http\Middleware\SecurityHeaders` a TODA respuesta. Viven en
    | configuracion y no cableadas en el middleware para que endurecerlas en
    | produccion sea un cambio de entorno y no un despliegue de codigo.
    |
    */

    'headers' => [

        /*
        | POLITICA DE CONTENIDO — RESTRICTIVA DESDE EL PRIMER DIA.
        |
        | `default-src 'none'` y ninguna excepcion. No es una postura ambiciosa:
        | es la descripcion honesta de lo que este backend hace. NO SIRVE HTML
        | NI ASSETS. Responde JSON, incluida la pantalla de consentimiento de
        | OAuth —`Passport::authorizationView` devuelve un JsonResponse, no una
        | vista—, y la SPA se sirve aparte. Un documento JSON no carga scripts,
        | ni hojas de estilo, ni tipografias, ni imagenes: negarlo todo no
        | rompe nada porque no hay nada que romper.
        |
        | POR QUE NO SE EMPIEZA PERMISIVA. Arrancar con `'unsafe-inline'` «para
        | no romper nada» y endurecer despues es el camino por el que las CSP
        | acaban con esa excepcion puesta para siempre: nadie vuelve a tocar una
        | politica que ya no se queja, y la excepcion sobrevive al motivo que la
        | justificaba. Si manana hace falta una excepcion concreta, se anade
        | aqui con su justificacion escrita al lado, nunca abriendo la politica
        | entera.
        |
        | SI ALGUIEN SIRVE LA SPA DESDE LARAVEL, ESTO LA ROMPE. Es deliberado:
        | obliga a decidir la politica de la SPA de forma consciente en vez de
        | heredar una pensada para otra cosa.
        |
        | Las cuatro directivas de abajo no las cubre `default-src` y cada una
        | cierra un vector propio:
        |   - base-uri     'none': impide que una inyeccion cambie la base de
        |                          las URL relativas.
        |   - form-action  'none': impide que un formulario inyectado envie a
        |                          un tercero.
        |   - frame-ancestors 'none': clickjacking. Es la version moderna de
        |                          X-Frame-Options, que se manda igual abajo
        |                          para navegadores que solo entienden aquella.
        |   - object-src   'none': plugins. Redundante con default-src, y se
        |                          escribe igual porque es la directiva que
        |                          algunos analizadores buscan por su nombre.
        */
        'content_security_policy' => env('CONTENT_SECURITY_POLICY', implode('; ', [
            "default-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'none'",
            "object-src 'none'",
        ])),

        /*
        | HSTS — CORTO EN DESARROLLO, LARGO EN PRODUCCION.
        |
        | El valor de produccion es 31536000 (un ano), que es el minimo que pide
        | la lista de precarga y el que corresponde a un dominio con certificado
        | de una autoridad reconocida.
        |
        | AQUI NO. En desarrollo el certificado es autofirmado, y HSTS es
        | PEGAJOSO: el navegador recuerda la instruccion durante todo el
        | `max-age` y, mientras dure, se niega a abrir el sitio por HTTP y no
        | deja saltarse el aviso del certificado. Un ano de max-age puesto por
        | descuido en un portatil deja el 6060 inservible en ese navegador hasta
        | que alguien encuentre el ajuste para borrarlo. Cinco minutos hacen que
        | el error se corrija solo.
        |
        | Nota: RFC 6797 §8.1 obliga a los navegadores a IGNORAR esta cabecera
        | cuando llega por HTTP, asi que hoy —el servidor de desarrollo es HTTP
        | pelado— no tiene efecto ninguno. El valor corto protege el dia que
        | alguien ponga TLS autofirmado delante, que es cuando si lo tendria.
        |
        | `includeSubDomains` y `preload` quedan APAGADOS por defecto: los dos
        | son compromisos que exceden a esta aplicacion. `includeSubDomains`
        | afecta a subdominios que quiza sirva otro equipo, y `preload` es
        | practicamente irreversible —salir de la lista tarda meses—. Se
        | encienden en produccion, por entorno, cuando alguien decida asumirlos.
        */
        'hsts' => [
            'max_age' => (int) env(
                'HSTS_MAX_AGE',
                env('APP_ENV') === 'production' ? 31_536_000 : 300
            ),
            'include_subdomains' => filter_var(
                env('HSTS_INCLUDE_SUBDOMAINS', false), FILTER_VALIDATE_BOOLEAN
            ),
            'preload' => filter_var(
                env('HSTS_PRELOAD', false), FILTER_VALIDATE_BOOLEAN
            ),
        ],

        /*
        | `no-referrer`: ni siquiera el origen viaja al destino. En una API de
        | credito, una URL de referencia puede delatar a que institucion
        | financiera pertenece el usuario; `strict-origin-when-cross-origin`
        | —el habitual— seguiria mandando el origen.
        */
        'referrer_policy' => env('REFERRER_POLICY', 'no-referrer'),

        /*
        | Cabeceras que revelan el producto y su version. No son una
        | vulnerabilidad por si mismas, pero le ahorran al atacante el paso de
        | averiguar contra que esta: sabiendo `PHP/8.4.24` puede ir directo a
        | los fallos conocidos de esa version en vez de sondear.
        |
        | `X-Powered-By` la anade PHP y se quita en el middleware, porque
        | `expose_php` es configuracion global del servidor y no se toca sin
        | consultar (CLAUDE.md §2). `Server` la pone el servidor web delante;
        | se intenta quitar igual por si la respuesta pasa entera.
        */
        'remove' => ['X-Powered-By', 'Server', 'X-AspNet-Version', 'X-Runtime'],
    ],

];
