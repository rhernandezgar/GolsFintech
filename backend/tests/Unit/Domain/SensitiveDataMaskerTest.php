<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SensitiveDataMasker;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * VUL-04 (CWE-532): ni la CURP, ni el RFC, ni el numero de tarjeta pueden llegar en
 * claro a la bitacora. El control esta en el dominio, no en el adaptador.
 */
final class SensitiveDataMaskerTest extends TestCase
{
    private SensitiveDataMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new SensitiveDataMasker;
    }

    public function test_it_redacts_by_key_name(): void
    {
        $masked = $this->masker->mask(['curp' => 'HEGG560427MVZRRL04', 'rfc' => 'GODE561231GR8']);

        $this->assertSame('[REDACTED]', $masked['curp']);
        $this->assertSame('[REDACTED]', $masked['rfc']);
    }

    public function test_it_redacts_by_value_shape_even_under_an_innocent_key(): void
    {
        $masked = $this->masker->mask(['note' => 'el prospecto HEGG560427MVZRRL04 confirmo sus datos']);

        $this->assertStringNotContainsString('HEGG560427MVZRRL04', $masked['note']);
        $this->assertStringContainsString('[REDACTED]', $masked['note']);
    }

    public function test_it_redacts_something_shaped_like_a_card_number(): void
    {
        $masked = $this->masker->mask(['detail' => 'pago con 4111111111111111']);

        $this->assertStringNotContainsString('4111111111111111', $masked['detail']);
    }

    public function test_it_redacts_nested_metadata(): void
    {
        $masked = $this->masker->mask(['provider' => ['response' => ['curp' => 'HEGG560427MVZRRL04']]]);

        $this->assertSame('[REDACTED]', $masked['provider']['response']['curp']);
    }

    public function test_it_leaves_harmless_data_untouched(): void
    {
        $masked = $this->masker->mask(['credit_type' => 'personal', 'term_months' => 12, 'amount' => '15000.00']);

        $this->assertSame(['credit_type' => 'personal', 'term_months' => 12, 'amount' => '15000.00'], $masked);
    }

    public function test_a_boolean_under_a_sensitive_key_survives(): void
    {
        // 'has_curp' => true no transporta el dato y si es informacion de auditoria.
        $masked = $this->masker->mask(['has_curp' => true, 'has_rfc' => false, 'curp' => 'HEGG560427MVZRRL04']);

        $this->assertTrue($masked['has_curp']);
        $this->assertFalse($masked['has_rfc']);
        $this->assertSame('[REDACTED]', $masked['curp']);
    }

    public function test_a_number_under_a_sensitive_key_is_still_redacted(): void
    {
        $masked = $this->masker->mask(['card_number' => 4111111111111111]);

        $this->assertSame('[REDACTED]', $masked['card_number']);
    }

    public function test_an_audit_event_cannot_be_built_carrying_a_curp(): void
    {
        // No hay forma de construir el evento sin pasar por el enmascarado.
        $event = new AuditEvent(
            eventType: AuditEventType::ProspectDataCaptured,
            affectedEntity: 'Prospect',
            affectedEntityId: 1,
            prospectId: 1,
            context: new AuditContext('prospect:1'),
            eventAt: new DateTimeImmutable('2026-08-21 10:00:00'),
            metadata: ['curp' => 'HEGG560427MVZRRL04', 'free_text' => 'CURP HEGG560427MVZRRL04'],
        );

        $this->assertStringNotContainsString('HEGG560427MVZRRL04', json_encode($event->metadata, JSON_THROW_ON_ERROR));
    }
}
