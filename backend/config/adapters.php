<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| Adaptadores de los puertos del dominio
|-------------------------------------------------------------------------------
|
| Cual adaptador queda activo se decide AQUI y en ningun otro lugar. Ni los casos
| de uso ni el dominio saben cual esta enchufado: preguntan por el puerto y el
| contenedor entrega lo que diga esta configuracion (CLAUDE.md seccion 3).
|
| Los cuatro servicios externos (OCR, identidad, tarjetas y notificaciones) van
| SIMULADOS: el entorno es de desarrollo y no hay convenios con INE ni RENAPO.
| Ningun adaptador de este archivo abre una conexion de red ni necesita
| credenciales. El adaptador real de cada uno se registra en
| AdapterServiceProvider cuando exista el convenio y la tarea que lo implemente.
|
| Los simuladores son DETERMINISTAS y saben fallar: el mismo dato de entrada
| produce siempre la misma respuesta, y hay entradas reservadas que provocan
| timeout, rechazo o indisponibilidad. Sin eso no se pueden probar los reintentos
| del worker (T7) ni los mensajes genericos de rechazo (T10).
|
*/

return [

    'persistence' => [
        'driver' => env('PERSISTENCE_DRIVER', 'eloquent'),
    ],

    'ocr' => [
        'driver' => env('OCR_DRIVER', 'simulated'),

        'simulated' => [
            // Fuerza el escenario para todo el entorno: extracted, timeout o
            // unreadable. Sin valor, cada documento decide el suyo por su ruta.
            'force_scenario' => env('OCR_SIMULATED_SCENARIO'),

            // Marcas reservadas en la ruta de almacenamiento del documento. Un
            // archivo llamado "ine-sandbox-timeout.jpg" siempre agota el tiempo.
            'scenario_markers' => [
                'timeout' => 'sandbox-timeout',
                'unreadable' => 'sandbox-unreadable',
            ],

            // Falla el encolado mismo, no el procesamiento: simula la cola caida.
            'fail_enqueue' => env('OCR_SIMULATED_FAIL_ENQUEUE', false),
        ],

        // Cola real sobre BullMQ que consume el worker de Node (T7). El
        // escenario simulado sigue viajando en el identificador del trabajo:
        // 'bullmq' cambia el transporte, no quien hace la extraccion. El
        // proveedor real de OCR entra como adaptador del worker.
        'bullmq' => [
            'queue' => env('OCR_QUEUE_NAME', 'ocr'),
            'job' => 'ocr.extract',

            // Intentos TOTALES, incluido el primero. Coincide con
            // IdentityDocument::MAX_OCR_ATTEMPTS: si BullMQ reintentara mas
            // veces que las que el dominio admite, el documento acabaria en un
            // estado que la entidad no acepta.
            'attempts' => (int) env('OCR_QUEUE_ATTEMPTS', 3),

            // Retardo base del backoff exponencial en milisegundos: 1 s, 2 s, 4 s.
            'backoff_ms' => (int) env('OCR_QUEUE_BACKOFF_MS', 1000),
        ],
    ],

    'identity' => [
        'driver' => env('IDENTITY_DRIVER', 'simulated'),

        'simulated' => [
            // verified, rejected, unavailable o fraud para todo el entorno.
            'force_scenario' => env('IDENTITY_SIMULATED_SCENARIO'),

            // CURP de laboratorio, como las identidades de prueba que entrega
            // cualquier proveedor de eKYC en su entorno sandbox. Empiezan por XEXX
            // —el prefijo generico oficial para persona extranjera—, de modo que
            // no pueden coincidir con la CURP de una persona real. Cualquier otra
            // CURP se verifica.
            'sandbox_curps' => [
                'rejected' => ['XEXX010101HNEXXXA4'],
                'unavailable' => ['XEXX020202MNEXXXA1'],
                'fraud' => ['XEXX030303HNEXXXA8'],
            ],
        ],
    ],

    'card' => [
        'driver' => env('CARD_DRIVER', 'simulated'),

        'simulated' => [
            'fail' => env('CARD_SIMULATED_FAIL', false),
            'validity_years' => 3,
        ],
    ],

    'notification' => [
        'driver' => env('NOTIFICATION_DRIVER', 'simulated'),

        'simulated' => [
            'fail' => env('NOTIFICATION_SIMULATED_FAIL', false),
        ],

        'bullmq' => [
            'queue' => env('NOTIFICATION_QUEUE_NAME', 'notifications'),
            'job' => 'notification.send',

            // Mas intentos y mas separacion que el OCR: una pasarela de correo
            // o SMS suele restablecerse sola, y reintentar un aviso no cuesta
            // reprocesar una imagen. 2 s, 4 s, 8 s, 16 s.
            'attempts' => (int) env('NOTIFICATION_QUEUE_ATTEMPTS', 5),
            'backoff_ms' => (int) env('NOTIFICATION_QUEUE_BACKOFF_MS', 2000),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Cola BullMQ compartida
    |---------------------------------------------------------------------------
    |
    | Prefijo y conexion que usan todos los adaptadores que encolan. El prefijo
    | tiene que ser el MISMO que el del worker de Node (worker/.env): si no
    | coinciden, el backend escribe en un juego de claves que el worker no mira
    | y los trabajos se pierden sin ruido.
    |
    | La conexion 'bullmq' de config/database.php es la unica sin prefijo de
    | Laravel, precisamente por esto.
    |
    */

    'bullmq' => [
        'connection' => 'bullmq',
        'prefix' => env('BULLMQ_PREFIX', 'bull'),
        'max_len_events' => (int) env('BULLMQ_MAX_LEN_EVENTS', 10000),
    ],

];
