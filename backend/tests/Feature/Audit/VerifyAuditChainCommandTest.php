<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditChain;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * audit:verify-chain sobre la bitacora real, manipulada como lo haria un atacante:
 * con SQL directo, por fuera de Eloquent, porque el modelo bloquea UPDATE y DELETE.
 *
 * Se comprueban las tres formas de manipulacion —alteracion, borrado intermedio e
 * insercion entre dos registros—, que el comando termina en codigo distinto de cero
 * para que sirva en integracion continua, y que dice EN QUE registro se rompe.
 */
final class VerifyAuditChainCommandTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $actor = 'system:test', array $metadata = []): AuditEvent
    {
        return new AuditEvent(
            eventType: AuditEventType::ProspectStarted,
            affectedEntity: 'Prospect',
            affectedEntityId: 1,
            prospectId: null,
            context: new AuditContext($actor, '203.0.113.10'),
            eventAt: new DateTimeImmutable('2026-08-22 09:00:00'),
            metadata: $metadata,
        );
    }

    private function appendThreeEvents(): void
    {
        $logger = $this->app->make(AuditLogger::class);

        $logger->append($this->event('prospect:1', ['step' => 'p1']));
        $logger->append($this->event('prospect:1', ['step' => 'p2']));
        $logger->append($this->event('prospect:1', ['step' => 'p3']));
    }

    /** @return array{0: int, 1: string} codigo de salida y salida del comando */
    private function runCommand(array $options = []): array
    {
        $exitCode = Artisan::call('audit:verify-chain', $options);

        return [$exitCode, Artisan::output()];
    }

    /** Fila coherente escrita a mano, con el id que se le indique. */
    private function forgeRow(int $id, ?string $previousHash, string $actor, string $eventType = 'prospect.started'): string
    {
        $eventAt = new DateTimeImmutable('2026-08-22 09:30:00');
        $metadata = ['step' => 'forged'];

        $currentHash = $this->app->make(AuditChain::class)->hashOfFields(
            previousHash: $previousHash,
            prospectId: null,
            affectedEntity: 'Prospect',
            affectedEntityId: 1,
            eventType: $eventType,
            actor: $actor,
            ipAddress: '203.0.113.10',
            eventAt: $eventAt,
            metadata: $metadata,
        );

        DB::table('audit_logs')->insert([
            'id' => $id,
            'prospect_id' => null,
            'affected_entity' => 'Prospect',
            'affected_entity_id' => 1,
            'event_type' => $eventType,
            'actor' => $actor,
            'ip_address' => '203.0.113.10',
            'metadata' => json_encode($metadata),
            'event_at' => $eventAt->format('Y-m-d H:i:s'),
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
        ]);

        return $currentHash;
    }

    // ------------------------------------------------------------- cadena integra

    public function test_an_untouched_chain_exits_with_zero(): void
    {
        $this->appendThreeEvents();

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Cadena integra', $output);
        $this->assertStringContainsString('3 registro(s)', $output);
    }

    public function test_an_empty_log_is_not_reported_as_tampering(): void
    {
        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('vacia', $output);
    }

    // -------------------------------------------------------- (1) alteracion de un registro

    public function test_altering_a_record_breaks_the_chain_and_the_command_fails(): void
    {
        $this->appendThreeEvents();
        $target = (int) AuditLogRecord::query()->orderBy('id')->skip(1)->value('id');

        DB::table('audit_logs')->where('id', $target)->update(['actor' => 'intruder']);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode, 'Un fallo de integridad tiene que detener la integracion continua.');
        $this->assertStringContainsString('CADENA ROTA', $output);
        $this->assertStringContainsString('contenido alterado', $output);
        // Ubicacion, no solo "invalida": el registro exacto y el actor sospechoso.
        $this->assertStringContainsString('#'.$target, $output);
        $this->assertStringContainsString('intruder', $output);
    }

    public function test_altering_only_the_metadata_of_a_record_is_detected(): void
    {
        $this->appendThreeEvents();
        $target = (int) AuditLogRecord::query()->orderBy('id')->value('id');

        DB::table('audit_logs')->where('id', $target)->update(['metadata' => json_encode(['step' => 'otro'])]);

        $this->assertSame(1, $this->runCommand()[0]);
    }

    public function test_altering_only_the_ip_address_of_a_record_is_detected(): void
    {
        $this->appendThreeEvents();
        $target = (int) AuditLogRecord::query()->orderBy('id')->value('id');

        DB::table('audit_logs')->where('id', $target)->update(['ip_address' => '198.51.100.99']);

        $this->assertSame(1, $this->runCommand()[0]);
    }

    // ------------------------------------------------------ (2) borrado de un registro intermedio

    public function test_deleting_an_intermediate_record_is_detected(): void
    {
        $this->appendThreeEvents();
        $ids = AuditLogRecord::query()->orderBy('id')->pluck('id')->all();

        DB::table('audit_logs')->where('id', $ids[1])->delete();

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('eslabon roto', $output);
        // Se senala el sucesor y con quien deberia enlazar ahora.
        $this->assertStringContainsString('#'.$ids[2], $output);
        $this->assertStringContainsString('#'.$ids[0].' que lo precede', $output);
    }

    // ------------------------------------------- (3) insercion de un registro entre dos existentes

    public function test_inserting_a_forged_record_between_two_existing_ones_is_detected(): void
    {
        // Ids espaciados a proposito: un atacante con escritura sobre la tabla elige
        // el id que quiera, y hace falta un hueco para meterse EN MEDIO.
        $first = $this->forgeRow(10, AuditChain::GENESIS, 'prospect:1');
        $second = $this->forgeRow(20, $first, 'prospect:1');
        $this->forgeRow(30, $second, 'prospect:1');

        $this->assertSame(0, $this->runCommand()[0], 'La cadena sembrada debe partir integra.');

        // El atacante hace bien su trabajo: enlaza con el #10 y calcula su hash. Su
        // registro pasa las dos comprobaciones; lo que no puede es arreglar al #20
        // sin rehacer todo lo que viene detras.
        $this->forgeRow(15, $first, 'intruder', 'credit.application_approved');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('eslabon roto', $output);
        $this->assertStringContainsString('#20', $output);
        $this->assertStringContainsString('#15 que lo precede', $output);
    }

    // ---------------------------------------------------------- truncado del final y ancla externa

    public function test_deleting_the_last_record_needs_the_external_anchor_to_be_detected(): void
    {
        $this->appendThreeEvents();
        $tip = (string) AuditLogRecord::query()->orderByDesc('id')->value('current_hash');

        DB::table('audit_logs')->where('current_hash', $tip)->delete();

        // Sin ancla la cadena que queda es coherente: es la limitacion documentada
        // del encadenamiento, no un fallo del verificador.
        $this->assertSame(0, $this->runCommand()[0]);

        [$exitCode, $output] = $this->runCommand(['--expect-tip' => $tip]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('LA PUNTA NO COINCIDE', $output);
    }

    public function test_the_anchor_matches_on_an_untouched_chain(): void
    {
        $this->appendThreeEvents();
        $tip = (string) AuditLogRecord::query()->orderByDesc('id')->value('current_hash');

        [$exitCode, $output] = $this->runCommand(['--expect-tip' => $tip]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('La punta coincide', $output);
    }

    // ------------------------------------------------------------------ VUL-04: enmascarar y luego firmar

    public function test_the_masking_happens_before_the_hash_so_a_legitimate_record_verifies(): void
    {
        $this->app->make(AuditLogger::class)->append($this->event('prospect:1', [
            'curp' => 'HEGG560427MVZRRL04',
            'note' => 'CURP HEGG560427MVZRRL04 validada',
        ]));

        $stored = (string) DB::table('audit_logs')->value('metadata');

        $this->assertStringNotContainsString('HEGG560427MVZRRL04', $stored);
        $this->assertStringContainsString('REDACTED', $stored);

        // Lo que importa: se firmo lo mismo que se guardo. Si el enmascaramiento
        // ocurriera despues de calcular el hash, este comando fallaria sobre datos
        // legitimos y la bitacora dejaria de servir como evidencia.
        $this->assertSame(0, $this->runCommand()[0]);
    }

    public function test_the_hash_is_computed_over_the_masked_metadata_and_not_the_raw_one(): void
    {
        $raw = ['curp' => 'HEGG560427MVZRRL04'];
        $this->app->make(AuditLogger::class)->append($this->event('prospect:1', $raw));

        $record = AuditLogRecord::query()->firstOrFail();
        $chain = $this->app->make(AuditChain::class);

        $overRaw = $chain->hashOfFields(
            previousHash: null, prospectId: null, affectedEntity: 'Prospect', affectedEntityId: 1,
            eventType: AuditEventType::ProspectStarted->value, actor: 'prospect:1', ipAddress: '203.0.113.10',
            eventAt: new DateTimeImmutable('2026-08-22 09:00:00'), metadata: $raw,
        );

        $this->assertNotSame($overRaw, (string) $record->current_hash);
    }

    // ------------------------------------------------------------------------ contrato del material

    public function test_the_hashed_columns_cover_the_whole_table(): void
    {
        // Si alguien anade una columna a audit_logs sin decidir si entra en el hash,
        // esta prueba se pone roja. Es la unica forma de que la lista documentada en
        // AuditChain no se quede atras del esquema en silencio.
        $columns = Schema::getColumnListing('audit_logs');
        $accounted = array_merge(AuditChain::HASHED_COLUMNS, AuditChain::UNHASHED_COLUMNS);

        sort($columns);
        sort($accounted);

        $this->assertSame($accounted, $columns);
    }

    public function test_the_json_output_carries_the_location_of_every_break(): void
    {
        $this->appendThreeEvents();
        $target = (int) AuditLogRecord::query()->orderBy('id')->value('id');

        DB::table('audit_logs')->where('id', $target)->update(['actor' => 'intruder']);

        [$exitCode, $output] = $this->runCommand(['--json' => true]);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['intact']);
        $this->assertSame(3, $payload['verified_records']);
        $this->assertSame($target, $payload['breaks'][0]['record_id']);
        $this->assertSame('content_altered', $payload['breaks'][0]['kind']);
        $this->assertNotNull($payload['tip_hash']);
    }
}
