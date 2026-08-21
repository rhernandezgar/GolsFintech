<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\AuditChain;
use App\Domain\Credit\AmortizationCalculator;
use App\Domain\Credit\CreditPolicy;
use App\Domain\Credit\CreditRulesEngine;
use App\Infrastructure\Security\PiiHasher;
use Illuminate\Support\ServiceProvider;

/**
 * Servicios propios de la aplicacion que no son puertos del dominio: la llave de
 * hash, la cadena de la bitacora y el motor de reglas ya armado.
 *
 * Los siete puertos NO se enlazan aqui: viven en AdapterServiceProvider, que es el
 * unico archivo que decide que adaptador queda activo (config/adapters.php).
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PiiHasher::class, static fn ($app): PiiHasher => new PiiHasher(
            (string) $app['config']->get('security.pii_hash_key')
        ));

        $this->app->singleton(AuditChain::class);

        // Motor de reglas: politica y calculadora explicitas, para que una politica
        // distinta (revision del area de riesgos) sea un cambio de una sola linea.
        $this->app->singleton(CreditPolicy::class, static fn (): CreditPolicy => CreditPolicy::default());
        $this->app->singleton(CreditRulesEngine::class, static fn ($app): CreditRulesEngine => new CreditRulesEngine(
            $app->make(CreditPolicy::class),
            new AmortizationCalculator,
        ));
    }

    public function boot(): void
    {
        //
    }
}
