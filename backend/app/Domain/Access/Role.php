<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Los cinco roles del control de acceso (RS-05).
 *
 * La tabla de la Fase 3 §4.9 se traslada aqui tal cual, con su alcance y sus
 * restricciones explicitas. El rol vive en el dominio y no en la base de datos
 * porque es una regla de negocio —quien puede ver que— y no un dato del
 * usuario: cambiarla debe romper una prueba, no requerir un UPDATE.
 *
 * La Fase 3 §4.9 enumera ademas un perfil "Servicio (worker)". No es un rol de
 * usuario: el worker de Node.js no se autentica como persona ni entra por el
 * servidor OAuth2, consume la cola con credenciales propias de minimo
 * privilegio. Por eso no aparece en esta enumeracion y se resuelve en T8, con
 * la cola.
 */
enum Role: string
{
    /** Su propia solicitud en curso y los datos que el mismo capturo. */
    case Prospect = 'prospect';

    /** Su linea de credito, su tarjeta y su contrato. */
    case Customer = 'customer';

    /** Consulta y seguimiento de solicitudes y clientes. */
    case Admin = 'admin';

    /** Lectura de la bitacora y de los expedientes. Solo lectura. */
    case Auditor = 'auditor';

    /** Parametros del motor de reglas e informacion economica del prospecto. */
    case RiskAnalyst = 'risk_analyst';

    /**
     * Permisos del rol, segun la tabla de alcance de la Fase 3 §4.9.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Prospecto: solo lo suyo. No accede a solicitudes de terceros.
            // Captura, sube su identificacion y acepta su simulacion: su
            // alcance incluye escritura sobre su propio expediente.
            self::Prospect => [
                Permission::CaptureOwnProspectData,
                Permission::UploadOwnIdentityDocument,
                Permission::AcceptOwnCreditSimulation,
                Permission::ViewOwnCreditApplication,
                Permission::ViewOwnDeclaredIncome,
            ],

            // Cliente: sus productos. Su alcance en la Fase 3 §4.9 es
            // consultivo —linea, tarjeta y contrato—, de modo que resulta de
            // solo lectura igual que el auditor. No es un descuido: quien
            // acepta la simulacion lo hace todavia como prospecto, antes del
            // alta como cliente. La tarjeta completa exige ademas
            // reautenticacion con segundo factor, que se comprueba aparte del
            // permiso.
            self::Customer => [
                Permission::ViewOwnCreditApplication,
                Permission::ViewOwnDeclaredIncome,
                Permission::ViewOwnCreditLine,
                Permission::ViewOwnMaskedCard,
                Permission::ViewOwnFullCard,
            ],

            // Administrador: consulta y seguimiento. Restricciones explicitas:
            // no modifica las reglas del motor ni altera la bitacora, y los
            // ingresos declarados no entran en su necesidad de conocer.
            self::Admin => [
                Permission::ViewAnyCreditApplication,
                Permission::UpdateCreditApplicationStatus,
                Permission::ViewIdentityDocumentImage,
                Permission::ReadAuditLog,
            ],

            // Auditor: lectura de la bitacora y de los expedientes. Solo
            // lectura, y los ingresos declarados permanecen restringidos.
            self::Auditor => [
                Permission::ViewAnyCreditApplication,
                Permission::ViewIdentityDocumentImage,
                Permission::ReadAuditLog,
            ],

            // Analista de riesgos: unico perfil administrativo con acceso a los
            // ingresos declarados. Restriccion explicita: no accede a las
            // imagenes de las identificaciones oficiales.
            self::RiskAnalyst => [
                Permission::ViewAnyCreditApplication,
                Permission::ViewAnyDeclaredIncome,
                Permission::ManageCreditRules,
            ],
        };
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Un rol de solo lectura no puede provocar ningun cambio de estado.
     *
     * Se deriva de los permisos en vez de declararse aparte para que no puedan
     * contradecirse: si alguien anade manana un permiso de escritura al
     * auditor, este metodo deja de decir que es de solo lectura y la prueba que
     * lo exige falla en ese mismo momento.
     */
    public function isReadOnly(): bool
    {
        foreach ($this->permissions() as $permission) {
            if ($permission->isWrite()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Un perfil administrativo opera el panel de consulta (P7) y por eso exige
     * segundo factor obligatorio (RS-01, Fase 3 §4.4).
     */
    public function isStaff(): bool
    {
        return match ($this) {
            self::Admin, self::Auditor, self::RiskAnalyst => true,
            self::Prospect, self::Customer => false,
        };
    }
}
