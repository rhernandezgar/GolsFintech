<?php

use App\Http\Middleware\EnforcePkceS256;

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Prefijo de los endpoints del servidor OAuth2
    |--------------------------------------------------------------------------
    |
    | El catalogo de servicios de la Fase 3 (MS-01) expone /auth/authorize y
    | /auth/token bajo el prefijo /api/v1, no bajo el /oauth por defecto de
    | Passport. Se ajusta aqui para que la ruta publicada coincida con el
    | contrato entregado.
    |
    */

    'path' => 'api/v1/auth',

    /*
    |--------------------------------------------------------------------------
    | Middleware de los endpoints del servidor OAuth2
    |--------------------------------------------------------------------------
    |
    | EnforcePkceS256 se aplica a todo el grupo, no solo a /authorize: la
    | exigencia de PKCE con S256 y el rechazo del flujo implicito son
    | invariantes del servidor y no de un endpoint concreto. Si manana Passport
    | anade una ruta al grupo, nace ya cubierta.
    |
    */

    'middleware' => [
        EnforcePkceS256::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens for
    | your application. By default, the keys are stored as local files but
    | can be set via environment variables when that is more convenient.
    |
    */

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

];
