<?php

declare(strict_types=1);

/*
| Parametros de negocio del credito.
|
| Aqui viven los numeros que la Fase 1 dejo al criterio del operador pero que
| deben poder cambiarse SIN tocar el codigo del dominio. La logica que los
| consume comprueba invariantes (por ejemplo, que el TTL sea positivo).
*/

return [
    /*
    | Vigencia de una simulacion, en segundos (Fase 2 §P5). La simulacion es
    | informativa y no vinculante hasta su autorizacion, y por eso lleva
    | vigencia propia: aceptar una oferta calculada hace horas con parametros
    | de riesgo que ya cambiaron es un defecto real.
    |
    | 30 minutos es tiempo suficiente para P5 -> P6 —la Fase 1 estima el
    | tramite completo en menos de 5 minutos, RNF-02—, y suficientemente corto
    | para que la oferta refleje el precio vigente.
    */
    'simulation_ttl_seconds' => (int) env('CREDIT_SIMULATION_TTL_SECONDS', 1800),
];
