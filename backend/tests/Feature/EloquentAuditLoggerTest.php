<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\AuditChain;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\ChainBreakKind;
use App\Domain\Port\AuditLogger;
use App\Infrastructure\Persistence\Eloquent\AuditChainInspector;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Bitacora append-only con encadenamiento SHA-256 (CLAUDE.md seccion 5).
 */
final class EloquentAuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private function event(AuditEventType $type, string $entity, ?int $entityId, array $metadata = []): AuditEvent
    {
        return new AuditEvent(
            eventType: $type,
            affectedEntity: $entity,
            affectedEntityId: $entityId,
            prospectId: null,
            context: new AuditContext('system:test', '198.51.100.7'),
            eventAt: new DateTimeImmutable('2026-08-21 10:00:00'),
            metadata: $metadata,
        );
    }

    private function appendThreeEvents(): void
    {
        $logger = $this->app->make(AuditLogger::class);

        $logger->append($this->event(AuditEventType::ProspectStarted, 'Prospect', null, ['step' => 'p1']));
        $logger->append($this->event(AuditEventType::DocumentUploaded, 'IdentityDocument', 10));
        $logger->append($this->event(AuditEventType::CreditSimulationGenerated, 'CreditSimulation', 20));
    }

    public function test_the_first_record_opens_the_chain_and_the_rest_link_to_it(): void
    {
        $this->appendThreeEvents();

        $records = AuditLogRecord::query()->orderBy('id')->get();

        $this->assertCount(3, $records);
        $this->assertNull($records[0]->previous_hash);
        $this->assertSame($records[0]->current_hash, $records[1]->previous_hash);
        $this->assertSame($records[1]->current_hash, $records[2]->previous_hash);

        foreach ($records as $record) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $record->current_hash);
        }
    }

    public function test_the_stored_hash_matches_the_domain_chain(): void
    {
        $event = $this->event(AuditEventType::ProspectStarted, 'Prospect', 5, ['step' => 'p1']);
        $this->app->make(AuditLogger::class)->append($event);

        $record = AuditLogRecord::query()->firstOrFail();

        $this->assertTrue(
            $this->app->make(AuditChain::class)->verify($event, null, (string) $record->current_hash)
        );
    }

    public function test_tampering_with_a_record_is_detectable(): void
    {
        $event = $this->event(AuditEventType::ProspectStarted, 'Prospect', 5, ['step' => 'p1']);
        $this->app->make(AuditLogger::class)->append($event);

        // Se altera la fila por fuera de Eloquent, como lo haria un atacante con
        // acceso a la base de datos: el hash guardado deja de corresponder.
        DB::table('audit_logs')->update(['actor' => 'intruder']);

        $record = AuditLogRecord::query()->firstOrFail();
        $this->assertSame('intruder', $record->actor);

        // La deteccion se comprueba recalculando sobre lo ALMACENADO. Verificar el
        // evento que quedo en memoria no acredita nada: ese objeto no lo toco nadie
        // y seguiria dando verde con la fila ya manipulada.
        $result = $this->app->make(AuditChainInspector::class)->verifyStoredChain();

        $this->assertFalse($result->isIntact());
        $this->assertSame(ChainBreakKind::ContentAltered, $result->firstBreak()->kind);
        $this->assertSame((int) $record->id, $result->firstBreak()->recordId);
    }

    public function test_the_log_rejects_updates(): void
    {
        $this->appendThreeEvents();

        $this->expectException(RuntimeException::class);

        AuditLogRecord::query()->firstOrFail()->update(['actor' => 'intruder']);
    }

    public function test_the_log_rejects_deletions(): void
    {
        $this->appendThreeEvents();

        $this->expectException(RuntimeException::class);

        AuditLogRecord::query()->firstOrFail()->delete();
    }

    public function test_sensitive_data_never_reaches_the_stored_metadata(): void
    {
        $this->app->make(AuditLogger::class)->append($this->event(
            AuditEventType::ProspectDataCaptured,
            'Prospect',
            1,
            ['curp' => 'HEGG560427MVZRRL04', 'note' => 'CURP HEGG560427MVZRRL04 validada']
        ));

        $stored = (string) DB::table('audit_logs')->value('metadata');

        $this->assertStringNotContainsString('HEGG560427MVZRRL04', $stored);
        $this->assertStringContainsString('REDACTED', $stored);
    }
}
