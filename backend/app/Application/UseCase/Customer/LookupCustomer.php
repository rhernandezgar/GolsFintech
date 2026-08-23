<?php

declare(strict_types=1);

namespace App\Application\UseCase\Customer;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Customer\RegisteredCustomer;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\CustomerRegistry;
use App\Domain\Shared\Folio;
use DateTimeImmutable;
use RuntimeException;

/**
 * P7: consulta del cliente por su numero (RF-11).
 *
 * El acceso a informacion personal es en si mismo un evento auditable (RS-06.a,
 * Fase 2 §P7): se registra tambien la consulta que si estaba autorizada, no
 * solo el intento rechazado. Sin esa entrada la bitacora no puede acreditar
 * quien consulto que datos.
 *
 * `customer_number` no es un identificador de portador: la respuesta la protege
 * la politica del endpoint (autenticacion + rol), y el numero se filtra por
 * FormRequest para descartar formatos absurdos antes de tocar la base.
 */
final readonly class LookupCustomer
{
    public function __construct(
        private CustomerRegistry $customers,
        private AuditLogger $auditLogger,
    ) {}

    public function execute(
        Folio $customerNumber,
        AuditContext $context,
        DateTimeImmutable $now,
    ): RegisteredCustomer {
        $customer = $this->customers->findByCustomerNumber($customerNumber);

        if ($customer === null) {
            // El intento fallido tambien queda registrado —lo que sostiene
            // RS-06.a y, ademas, alimenta la deteccion de barridos por numero
            // (riesgo R-01)—.
            $this->auditLogger->append(new AuditEvent(
                eventType: AuditEventType::AuthorizationDenied,
                affectedEntity: 'Customer',
                affectedEntityId: null,
                prospectId: null,
                context: $context,
                eventAt: $now,
                metadata: [
                    'reason' => 'customer_not_found',
                    'customer_number' => $customerNumber->value,
                ],
            ));

            throw new RuntimeException('No se encontro un cliente con ese numero.');
        }

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::CustomerLookedUp,
            affectedEntity: 'Customer',
            affectedEntityId: $customer->customerId,
            prospectId: null,
            context: $context,
            eventAt: $now,
            metadata: [
                'customer_number' => $customer->customerNumber,
                // Los ultimos cuatro digitos NO son PAN: es lo que el cliente
                // usa para reconocer su propia tarjeta y por eso se conserva.
                'card_last_four' => $customer->cardLastFour,
                'line_status' => $customer->lineStatus,
            ],
        ));

        return $customer;
    }
}
