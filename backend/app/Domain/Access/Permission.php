<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Permisos concretos del control de acceso basado en roles (RS-05).
 *
 * El rol por si solo no es un permiso: la Fase 3 §4.9 define para cada rol un
 * alcance autorizado y unas restricciones explicitas, y son esas restricciones
 * —no el nombre del rol— las que se verifican en el servidor. Un permiso
 * responde siempre a la pregunta "que puede hacerse", nunca a "quien es".
 *
 * El sufijo distingue el alcance:
 *   *_OWN  el usuario opera sobre el registro que le pertenece;
 *   *_ANY  el usuario opera sobre el registro de cualquier otro.
 *
 * Tener *_ANY no exime de la autorizacion a nivel de objeto: sigue siendo el
 * objeto concreto el que decide, no el permiso generico (Fase 3 §4.9).
 */
enum Permission: string
{
    // --- Captura del propio prospecto ---------------------------------------
    // El alcance del prospecto en la Fase 3 §4.9 es "su propia solicitud en
    // curso y los datos que el mismo capturo": capturar es escribir. Los
    // endpoints que ejercen estos permisos llegan en T7 a T9; el permiso se
    // declara aqui porque es la matriz de roles la que decide quien escribe, y
    // sin el la marca de solo lectura de Role::isReadOnly() seria falsa.
    case CaptureOwnProspectData = 'prospect.data.capture.own';
    case UploadOwnIdentityDocument = 'identity_document.upload.own';
    case AcceptOwnCreditSimulation = 'credit_simulation.accept.own';

    // --- Solicitudes de credito ---------------------------------------------
    case ViewOwnCreditApplication = 'credit_application.view.own';
    case ViewAnyCreditApplication = 'credit_application.view.any';
    case UpdateCreditApplicationStatus = 'credit_application.update_status';

    // --- Informacion economica declarada ------------------------------------
    // Los ingresos declarados son el dato que la Fase 2 §P7 deja "restringido a
    // perfiles con necesidad justificada de conocerlos": entre los perfiles
    // administrativos, solo el analista de riesgos.
    case ViewOwnDeclaredIncome = 'prospect.declared_income.view.own';
    case ViewAnyDeclaredIncome = 'prospect.declared_income.view.any';

    // --- Identificaciones oficiales -----------------------------------------
    case ViewIdentityDocumentImage = 'identity_document.image.view';

    // --- Bitacora de auditoria ----------------------------------------------
    // No existe permiso de escritura: la bitacora es append-only y solo la
    // escribe la propia aplicacion al registrar un evento.
    case ReadAuditLog = 'audit_log.read';

    // --- Motor de reglas de credito -----------------------------------------
    case ManageCreditRules = 'credit_rules.manage';

    // --- Productos del cliente ----------------------------------------------
    case ViewOwnCreditLine = 'credit_line.view.own';
    case ViewOwnMaskedCard = 'card.view_masked.own';
    /** Exige reautenticacion con segundo factor (Fase 3 §4.9, pantalla P6). */
    case ViewOwnFullCard = 'card.view_full.own';

    // --- Consulta de clientes (P7, panel administrativo) --------------------
    // La vista de P7 la usa el personal administrativo con necesidad de dar
    // seguimiento a un cliente: admin, auditor y analista de riesgos.
    // Prospecto y cliente NO lo tienen -su alcance es solo lo propio-.
    case ViewAnyCustomer = 'customer.view.any';

    /**
     * Un permiso de escritura modifica estado; el resto solo lee.
     *
     * Sirve para comprobar de un vistazo que un rol de solo lectura no recibio
     * por descuido un permiso que escribe.
     */
    public function isWrite(): bool
    {
        return match ($this) {
            self::CaptureOwnProspectData,
            self::UploadOwnIdentityDocument,
            self::AcceptOwnCreditSimulation,
            self::UpdateCreditApplicationStatus,
            self::ManageCreditRules => true,
            default => false,
        };
    }
}
