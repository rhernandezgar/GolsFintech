<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\UseCase\Credit\SimulateCredit;
use App\Domain\Audit\AuditChain;
use App\Domain\Credit\AmortizationCalculator;
use App\Domain\Credit\CreditPolicy;
use App\Domain\Credit\CreditRulesEngine;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\CreditApplicationRepository;
use App\Domain\Port\IdentityValidationRepository;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\ReapplicationPolicy;
use App\Infrastructure\Security\PiiHasher;
use Illuminate\Contracts\Foundation\Application;
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

        // SimulateCredit no es un puerto: es un caso de uso. Se registra aqui
        // porque su constructor recibe un `int` (el TTL de la simulacion, en
        // segundos) que el autowiring no sabe resolver. Los demas casos de uso
        // se resuelven solos porque sus dependencias son todas objetos.
        $this->app->bind(SimulateCredit::class, static fn (Application $app): SimulateCredit => new SimulateCredit(
            prospects: $app->make(ProspectRepository::class),
            validations: $app->make(IdentityValidationRepository::class),
            applications: $app->make(CreditApplicationRepository::class),
            rulesEngine: $app->make(CreditRulesEngine::class),
            auditLogger: $app->make(AuditLogger::class),
            simulationTtlSeconds: (int) $app['config']->get('credit.simulation_ttl_seconds', 1800),
        ));

        // Misma razon: la politica de reintento recibe tres enteros que salen
        // de configuracion. Es un singleton porque no tiene estado y los dos
        // casos de uso que la consumen tienen que decidir con las MISMAS
        // ventanas: si cada uno construyera la suya, la rama manual y la de OCR
        // podrian divergir sin que nada lo delatara.
        $this->app->singleton(
            ReapplicationPolicy::class,
            static fn (Application $app): ReapplicationPolicy => new ReapplicationPolicy(
                inProgressWindowMinutes: (int) $app['config']->get('security.reapplication.in_progress_window_minutes', 10),
                rejectedWindowHours: (int) $app['config']->get('security.reapplication.rejected_window_hours', 24),
                maxRejectedAttempts: (int) $app['config']->get('security.reapplication.max_rejected_attempts', 3),
            )
        );
    }

    public function boot(): void
    {
        //
    }
}
