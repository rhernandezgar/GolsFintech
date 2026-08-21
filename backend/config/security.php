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

];
