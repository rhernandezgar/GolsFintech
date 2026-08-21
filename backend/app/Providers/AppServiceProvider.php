<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\AuditChain;
use App\Domain\Credit\AmortizationCalculator;
use App\Domain\Credit\CreditPolicy;
use App\Domain\Credit\CreditRulesEngine;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\DocumentRepository;
use App\Domain\Port\ProspectRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentAuditLogger;
use App\Infrastructure\Persistence\Eloquent\EloquentDocumentRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentProspectRepository;
use App\Infrastructure\Security\PiiHasher;
use Illuminate\Support\ServiceProvider;

/**
 * Aqui se enchufan los puertos del dominio con sus adaptadores. Es el unico punto
 * del sistema que conoce ambas capas a la vez; cambiar de proveedor o de motor de
 * persistencia se resuelve cambiando una linea de este archivo.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PiiHasher::class, static fn ($app): PiiHasher => new PiiHasher(
            (string) $app['config']->get('security.pii_hash_key')
        ));

        $this->app->singleton(AuditChain::class);

        // Puertos con adaptador implementado.
        $this->app->bind(ProspectRepository::class, EloquentProspectRepository::class);
        $this->app->bind(DocumentRepository::class, EloquentDocumentRepository::class);
        $this->app->bind(AuditLogger::class, EloquentAuditLogger::class);

        // Motor de reglas: politica y calculadora explicitas, para que una politica
        // distinta (revision del area de riesgos) sea un cambio de una sola linea.
        $this->app->singleton(CreditPolicy::class, static fn (): CreditPolicy => CreditPolicy::default());
        $this->app->singleton(CreditRulesEngine::class, static fn ($app): CreditRulesEngine => new CreditRulesEngine(
            $app->make(CreditPolicy::class),
            new AmortizationCalculator(),
        ));

        // Puertos declarados y todavia sin adaptador, por tarea del plan:
        //   OcrService e IdentityValidator -> T7 (OCR y proveedores de identidad)
        //   NotificationSender             -> T8 (notificaciones)
        //   CardIssuer                     -> T9 (alta de cliente y tarjeta)
        // Se dejan sin enlazar a proposito: un adaptador falso enlazado aqui daria
        // por implementado algo que no lo esta.
    }

    public function boot(): void
    {
        //
    }
}
