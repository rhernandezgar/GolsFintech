<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Catalogo de eventos de la bitacora (audit_logs.event_type).
 *
 * Es un enum y no texto libre para que la bitacora se pueda consultar y auditar por
 * tipo sin depender de como se haya escrito el evento en cada punto del codigo.
 */
enum AuditEventType: string
{
    // --- Autenticacion y control de acceso (RS-01, RS-05, RS-06) -----------
    // El acceso a informacion personal es en si mismo un evento auditable
    // (Fase 2, pantalla P7): tambien se registra la consulta que si estaba
    // autorizada, no solo el intento rechazado.
    case AuthenticationSucceeded = 'auth.login_succeeded';
    case AuthenticationFailed = 'auth.login_failed';
    case TwoFactorChallengeFailed = 'auth.two_factor_failed';
    case SessionEnded = 'auth.session_ended';
    case AuthorizationDenied = 'auth.authorization_denied';
    case CreditApplicationViewed = 'credit_application.viewed';
    case CustomerLookedUp = 'customer.looked_up';
    case AuditLogViewed = 'audit_log.viewed';

    case ProspectStarted = 'prospect.started';
    case ProspectDataCaptured = 'prospect.data_captured';
    case ProspectDataConfirmed = 'prospect.data_confirmed';
    case ProspectAbandoned = 'prospect.abandoned';
    case DocumentUploaded = 'document.uploaded';
    case DocumentOcrQueued = 'document.ocr_queued';
    case DocumentOcrCompleted = 'document.ocr_completed';
    case DocumentOcrFailed = 'document.ocr_failed';
    case IdentityValidationRequested = 'identity.validation_requested';
    case IdentityValidationSucceeded = 'identity.validation_succeeded';
    case IdentityValidationRejected = 'identity.validation_rejected';
    // El proveedor no respondio "verificado" ni "no verificado" —esta caido o
    // devuelve "en proceso"—. Distinto de rejected: rechazar por caida del
    // proveedor negaria credito a alguien con identidad valida (riesgo R-03).
    case IdentityValidationDeferred = 'identity.validation_deferred';
    case CreditApplicationOpened = 'credit_application.opened';
    case CreditApplicationApproved = 'credit_application.approved';
    case CreditSimulationGenerated = 'credit_simulation.generated';
    case CreditSimulationAccepted = 'credit_simulation.accepted';
    case CreditSimulationRejected = 'credit_simulation.rejected';
    case CustomerCreated = 'customer.created';
    case CreditLineOpened = 'credit_line.opened';
    case CardIssued = 'card.issued';
    // Cuando el emisor externo falla, el cliente y su linea ya estan creados y
    // el credito autorizado no se pierde: el expediente queda en un estado
    // recuperable, sin tarjeta, y hace falta un evento propio para saberlo.
    case CardIssuanceFailed = 'card.issuance_failed';
}
