<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * En tests, el contenedor se reutiliza entre peticiones y el guard de
     * Passport cachea el `user()` resuelto. Si en la misma prueba se emiten
     * dos tokens distintos y se ejerce el segundo tras haber ejercido el
     * primero, el guard devuelve al usuario del primero y el aislamiento por
     * token se evapora **solo en tests**. En produccion no existe: cada
     * request abre un ciclo del kernel nuevo.
     *
     * Un test de aislamiento que olvidara resetear el guard **pasaria en
     * falso** —el modo de fallo peligroso—: no fallaria, solo dejaria de
     * comprobar lo que dice comprobar. Por eso el reset se aplica por
     * infraestructura, aqui, cada vez que la prueba fija la cabecera
     * `Authorization`. Cubre `withHeader('Authorization', ...)` y `withToken`
     * (que va por el mismo camino), sin tocar `Passport::actingAs()`, que
     * establece el usuario en el guard por otra via.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $_) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $this->app['auth']->forgetGuards();
                break;
            }
        }

        return parent::withHeaders($headers);
    }

    public function withHeader(string $name, string $value): static
    {
        if (strcasecmp($name, 'Authorization') === 0) {
            $this->app['auth']->forgetGuards();
        }

        return parent::withHeader($name, $value);
    }
}
