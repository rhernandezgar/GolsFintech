<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Algoritmo de derivacion de contrasenas
    |---------------------------------------------------------------------------
    |
    | Argon2id, conforme a la regla de seguridad no negociable 6 y a la Fase 3
    | §4.5. Un hash criptografico rapido como SHA-256 es inadecuado para
    | contrasenas precisamente por su velocidad; Argon2id impone un costo
    | configurable en memoria, tiempo y paralelismo que resiste el hardware
    | especializado. La sal la genera la propia funcion, unica por credencial.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |---------------------------------------------------------------------------
    | Costo de Argon2id
    |---------------------------------------------------------------------------
    |
    | 64 MiB de memoria y 4 pasadas es el punto de partida que recomienda la
    | hoja de referencia de OWASP para Argon2id. Los parametros se guardan
    | dentro del propio hash, asi que subirlos mas adelante no invalida las
    | credenciales ya almacenadas: se rehashean al siguiente inicio de sesion.
    |
    | En pruebas se bajan por variable de entorno para que la suite no tarde
    | segundos por cada usuario creado.
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

];
