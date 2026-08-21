<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La matriz de roles de la Fase 3 §4.9, comprobada campo por campo.
 *
 * Sin base de datos ni framework: el control de acceso es una regla de negocio
 * y se verifica como tal. Si alguien amplia el alcance de un rol, aqui se ve.
 */
final class RoleAccessMatrixTest extends TestCase
{
    #[Test]
    public function the_five_roles_of_the_design_exist_and_no_others(): void
    {
        $this->assertSame(
            ['prospect', 'customer', 'admin', 'auditor', 'risk_analyst'],
            array_column(Role::cases(), 'value')
        );
    }

    #[Test]
    public function every_role_grants_at_least_one_permission(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertNotEmpty(
                $role->permissions(),
                sprintf('El rol %s no puede hacer nada.', $role->value)
            );
        }
    }

    // --- Auditor: solo lectura ----------------------------------------------

    #[Test]
    public function the_auditor_is_read_only(): void
    {
        $this->assertTrue(Role::Auditor->isReadOnly());

        foreach (Role::Auditor->permissions() as $permission) {
            $this->assertFalse(
                $permission->isWrite(),
                sprintf('El auditor no puede tener el permiso de escritura %s.', $permission->value)
            );
        }
    }

    #[Test]
    public function the_auditor_reads_the_audit_log_and_the_files(): void
    {
        $this->assertTrue(Role::Auditor->hasPermission(Permission::ReadAuditLog));
        $this->assertTrue(Role::Auditor->hasPermission(Permission::ViewAnyCreditApplication));
        $this->assertTrue(Role::Auditor->hasPermission(Permission::ViewIdentityDocumentImage));
    }

    #[Test]
    public function exactly_the_auditor_and_the_customer_are_read_only(): void
    {
        // El auditor lo es por la restriccion explicita de la Fase 3 §4.9.
        // El cliente lo es porque su alcance en esa misma tabla es consultivo:
        // linea, tarjeta y contrato. Quien acepta la simulacion lo hace
        // todavia como prospecto, antes del alta como cliente.
        $readOnly = array_values(array_filter(
            Role::cases(),
            static fn (Role $role): bool => $role->isReadOnly()
        ));

        $this->assertSame([Role::Customer, Role::Auditor], $readOnly);
    }

    #[Test]
    public function the_prospect_writes_over_its_own_file(): void
    {
        // El alcance del prospecto incluye "los datos que el mismo capturo":
        // capturar es escribir, y por eso no es un rol de solo lectura.
        $this->assertFalse(Role::Prospect->isReadOnly());
        $this->assertTrue(Role::Prospect->hasPermission(Permission::CaptureOwnProspectData));
        $this->assertTrue(Role::Prospect->hasPermission(Permission::UploadOwnIdentityDocument));
        $this->assertTrue(Role::Prospect->hasPermission(Permission::AcceptOwnCreditSimulation));
    }

    // --- Ingresos declarados: restringidos ----------------------------------

    #[Test]
    public function only_the_risk_analyst_reads_the_declared_income_of_others(): void
    {
        $this->assertTrue(Role::RiskAnalyst->hasPermission(Permission::ViewAnyDeclaredIncome));

        foreach ([Role::Prospect, Role::Customer, Role::Admin, Role::Auditor] as $role) {
            $this->assertFalse(
                $role->hasPermission(Permission::ViewAnyDeclaredIncome),
                sprintf('El rol %s no debe ver los ingresos declarados de terceros.', $role->value)
            );
        }
    }

    #[Test]
    public function the_prospect_reads_the_income_it_declared_itself(): void
    {
        $this->assertTrue(Role::Prospect->hasPermission(Permission::ViewOwnDeclaredIncome));
        $this->assertFalse(Role::Prospect->hasPermission(Permission::ViewAnyDeclaredIncome));
    }

    // --- Restricciones explicitas de la Fase 3 §4.9 -------------------------

    #[Test]
    public function the_administrator_does_not_touch_the_credit_rules_engine(): void
    {
        $this->assertFalse(Role::Admin->hasPermission(Permission::ManageCreditRules));
        $this->assertTrue(Role::RiskAnalyst->hasPermission(Permission::ManageCreditRules));
    }

    #[Test]
    public function the_risk_analyst_does_not_see_the_official_id_images(): void
    {
        $this->assertFalse(Role::RiskAnalyst->hasPermission(Permission::ViewIdentityDocumentImage));
    }

    #[Test]
    public function the_prospect_has_no_permission_over_third_party_records(): void
    {
        foreach (Role::Prospect->permissions() as $permission) {
            $this->assertStringNotContainsString(
                '.any',
                $permission->value,
                sprintf('El prospecto no debe tener alcance sobre terceros: %s.', $permission->value)
            );
        }
    }

    #[Test]
    public function the_customer_has_no_permission_over_third_party_records(): void
    {
        foreach (Role::Customer->permissions() as $permission) {
            $this->assertStringNotContainsString('.any', $permission->value);
        }
    }

    // --- Perfiles administrativos: segundo factor obligatorio ---------------

    #[DataProvider('staffRoles')]
    #[Test]
    public function administrative_profiles_require_a_second_factor(Role $role): void
    {
        $this->assertTrue($role->isStaff());
    }

    /** @return array<string, array{Role}> */
    public static function staffRoles(): array
    {
        return [
            'admin' => [Role::Admin],
            'auditor' => [Role::Auditor],
            'risk_analyst' => [Role::RiskAnalyst],
        ];
    }

    #[Test]
    public function the_prospect_and_the_customer_are_not_administrative_profiles(): void
    {
        $this->assertFalse(Role::Prospect->isStaff());
        $this->assertFalse(Role::Customer->isStaff());
    }

    #[Test]
    public function read_only_is_derived_from_the_permissions_and_cannot_contradict_them(): void
    {
        foreach (Role::cases() as $role) {
            $hasWrite = false;

            foreach ($role->permissions() as $permission) {
                $hasWrite = $hasWrite || $permission->isWrite();
            }

            $this->assertSame(
                ! $hasWrite,
                $role->isReadOnly(),
                sprintf('isReadOnly() contradice los permisos de %s.', $role->value)
            );
        }
    }
}
