<?php

declare(strict_types=1);

namespace App\Application\UseCase\Identity;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Exception\DocumentNotOwnedByProspectException;
use App\Domain\Exception\ProspectDataNotConfirmedException;
use App\Domain\Identity\OverallValidationStatus;
use App\Domain\Identity\RecordedIdentityValidation;
use App\Domain\Port\AuditLogger;
use App\Domain\Port\DocumentRepository;
use App\Domain\Port\IdentityValidationRepository;
use App\Domain\Port\IdentityValidator;
use App\Domain\Port\ProspectRepository;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use RuntimeException;

/**
 * P4: validacion de identidad contra INE y RENAPO (RF-03, RF-04).
 *
 * Se orquesta aqui y no dentro del adaptador porque el resultado dispara dos
 * escrituras que van juntas y no deben separarse: la fila de
 * `identity_validations` y su evento de bitacora. Si el proveedor responde y no
 * queda rastro auditable de esa respuesta, el hueco solo aparece en una
 * revision, y llenar la bitacora despues no reconstruye lo que se le mostro al
 * prospecto (RS-06).
 *
 * ### Precondicion: expediente confirmado (Fase 1)
 *
 * P4 va DESPUES de P2, y P2 termina en `confirmData()`. Ejecutar validacion
 * sobre un prospecto sin `data_confirmed` consultaria a INE y RENAPO con datos
 * incompletos, cobraria la llamada y no serviria para autorizar el credito.
 * Se corta con `ProspectDataNotConfirmedException`.
 *
 * ### Los tres desenlaces del validador y por que hay tres eventos
 *
 * El evento de cierre distingue **tres** estados, no dos:
 *
 * - `_succeeded` cuando INE, RENAPO, coincidencia de datos y vigencia del
 *   documento responden todos verificado y no hay marca antifraude.
 * - `_rejected` cuando algo respondio expresamente no verificado o la
 *   evaluacion antifraude marco.
 * - `_deferred` cuando el proveedor devolvio "en proceso" o "no disponible"
 *   sin decir verificado ni no verificado. Colapsar esto a `_rejected` seria
 *   negar credito a alguien con identidad valida por una caida temporal del
 *   proveedor (riesgo R-03). Se guarda la fila y P4 puede reintentarse.
 *
 * `identity.validation_requested` se emite ANTES de llamar al proveedor: si
 * la llamada revienta con excepcion, queda constancia de que se intento —lo
 * que un pico repetido sobre el mismo expediente delataria como riesgo R-01—.
 *
 * ### CURP fuera del log
 *
 * Nunca se registra CURP ni RFC en la metadata del evento (regla de seguridad
 * no negociable 1, VUL-04): la respuesta cruda del proveedor viene ya
 * enmascarada desde el adaptador y no se copia al log.
 */
final readonly class ValidateIdentity
{
    public function __construct(
        private ProspectRepository $prospects,
        private DocumentRepository $documents,
        private IdentityValidator $validator,
        private IdentityValidationRepository $validations,
        private AuditLogger $auditLogger,
    ) {}

    public function execute(
        Uuid $prospectPublicId,
        ?Uuid $documentPublicId,
        AuditContext $context,
        DateTimeImmutable $now,
    ): RecordedIdentityValidation {
        $prospect = $this->prospects->findByPublicId($prospectPublicId);

        if ($prospect === null) {
            throw new RuntimeException('El prospecto indicado no existe.');
        }

        if (! $prospect->hasConfirmedData()) {
            throw new ProspectDataNotConfirmedException(
                'La validacion de identidad exige el expediente confirmado.'
            );
        }

        $document = null;
        $documentId = null;

        if ($documentPublicId !== null) {
            $document = $this->documents->findByPublicId($documentPublicId);

            if ($document === null || $document->prospectId() !== $prospect->id()) {
                // Sin filtrar por prospecto se podria validar contra la
                // identificacion de otro expediente (CWE-639). No pasa.
                throw new DocumentNotOwnedByProspectException(
                    'El documento indicado no pertenece al prospecto.'
                );
            }

            $documentId = $document->id();
        }

        $this->auditLogger->append(new AuditEvent(
            eventType: AuditEventType::IdentityValidationRequested,
            affectedEntity: 'IdentityValidation',
            affectedEntityId: null,
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'has_document' => $document !== null,
            ],
        ));

        $result = $this->validator->validate($prospect, $document);
        $recorded = $this->validations->save((int) $prospect->id(), $documentId, $result, $now);

        $closingEvent = match ($result->overallStatus()) {
            OverallValidationStatus::Verified => AuditEventType::IdentityValidationSucceeded,
            OverallValidationStatus::Rejected => AuditEventType::IdentityValidationRejected,
            OverallValidationStatus::Pending => AuditEventType::IdentityValidationDeferred,
        };

        $this->auditLogger->append(new AuditEvent(
            eventType: $closingEvent,
            affectedEntity: 'IdentityValidation',
            affectedEntityId: $recorded->id,
            prospectId: $prospect->id(),
            context: $context,
            eventAt: $now,
            metadata: [
                'verification_folio' => $result->verificationFolio,
                'overall_status' => $result->overallStatus()->value,
                'ine_status' => $result->ineStatus->value,
                'renapo_status' => $result->renapoStatus->value,
                'data_match_status' => $result->dataMatchStatus->value,
                'document_validity_status' => $result->documentValidityStatus->value,
                'fraud_flagged' => $result->fraudFlagged,
                'attempts' => $recorded->attempts,
            ],
        ));

        return $recorded;
    }
}
