<?php

declare(strict_types=1);

namespace Tests\Feature\Persistence;

use App\Domain\Identity\IdentityValidationResult;
use App\Domain\Identity\OverallValidationStatus;
use App\Domain\Identity\VerificationStatus;
use App\Domain\Port\IdentityValidationRepository;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adaptador de persistencia de la validacion de identidad (P4).
 *
 * Interesa comprobar tres cosas del disenio:
 * - la ida y vuelta result -> fila -> RecordedIdentityValidation preserva todos
 *   los campos, con `provider_response` como JSON;
 * - `attempts` crece por prospecto y no por fila: un numero alto sobre el mismo
 *   expediente es la senal del riesgo R-01;
 * - la respuesta cruda del proveedor **ya llega enmascarada** (VUL-04); el repo
 *   no la enmascara, solo la almacena.
 */
final class EloquentIdentityValidationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): IdentityValidationRepository
    {
        return $this->app->make(IdentityValidationRepository::class);
    }

    private function makeProspect(): int
    {
        return (int) DB::table('prospects')->insertGetId([
            'public_id' => (string) Uuid::generate(),
            'full_name' => 'Ana Perez Lopez',
            'capture_method' => 'manual',
            'capture_status' => 'data_captured',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function verifiedResult(?string $folio = null, array $masked = []): IdentityValidationResult
    {
        return new IdentityValidationResult(
            verificationFolio: $folio ?? 'VER-'.substr((string) Uuid::generate(), 0, 8),
            ineStatus: VerificationStatus::Verified,
            renapoStatus: VerificationStatus::Verified,
            dataMatchStatus: VerificationStatus::Verified,
            documentValidityStatus: VerificationStatus::Verified,
            fraudFlagged: false,
            maskedProviderResponse: $masked,
        );
    }

    public function test_it_persists_the_result_and_returns_a_recorded_validation_with_id(): void
    {
        $prospectId = $this->makeProspect();
        $result = $this->verifiedResult('VER-000001', ['provider' => 'ine', 'trace' => 'abc']);
        $at = new DateTimeImmutable('2026-08-22 12:00:00');

        $recorded = $this->repository()->save($prospectId, null, $result, $at);

        $this->assertGreaterThan(0, $recorded->id);
        $this->assertSame(1, $recorded->attempts);
        $this->assertSame($result->verificationFolio, $recorded->result->verificationFolio);
        $this->assertSame(OverallValidationStatus::Verified, $recorded->result->overallStatus());
        $this->assertSame(
            $at->format(DateTimeImmutable::ATOM),
            $recorded->validatedAt->format(DateTimeImmutable::ATOM)
        );
        $this->assertSame(1, DB::table('identity_validations')->count());
    }

    public function test_the_attempts_counter_grows_by_prospect_and_not_by_row(): void
    {
        $first = $this->makeProspect();
        $second = $this->makeProspect();
        $at = new DateTimeImmutable('2026-08-22 12:00:00');

        // Dos intentos sobre el mismo prospecto: 1 y luego 2.
        $one = $this->repository()->save($first, null, $this->verifiedResult('VER-A-1'), $at);
        $two = $this->repository()->save($first, null, $this->verifiedResult('VER-A-2'), $at);

        // Otro prospecto: su contador arranca en 1 pese a que ya haya filas.
        $other = $this->repository()->save($second, null, $this->verifiedResult('VER-B-1'), $at);

        $this->assertSame(1, $one->attempts);
        $this->assertSame(2, $two->attempts);
        $this->assertSame(1, $other->attempts);
    }

    public function test_the_provider_response_is_persisted_as_json_and_reads_back_as_array(): void
    {
        $prospectId = $this->makeProspect();
        // El adaptador del proveedor ya enmascaro CURP/RFC antes de llegar aqui
        // (VUL-04). El repositorio se limita a guardar el arbol que recibe.
        $masked = [
            'ine' => ['status' => 'verified', 'curp' => 'HEGG******04'],
            'renapo' => ['status' => 'verified'],
        ];
        $at = new DateTimeImmutable('2026-08-22 12:00:00');

        $this->repository()->save($prospectId, null, $this->verifiedResult('VER-JSON', $masked), $at);

        $raw = DB::table('identity_validations')->value('provider_response');
        $this->assertIsString($raw);
        // MySQL normaliza el orden de las claves de un JSON al persistirlo, por
        // eso el compare va por igualdad canonicalizada y no por identidad.
        $this->assertEqualsCanonicalizing($masked, json_decode((string) $raw, true));

        // Y al reconstruir vuelve como arbol PHP, no como cadena.
        $loaded = $this->repository()->findLatestFor($prospectId);
        $this->assertNotNull($loaded);
        $this->assertEqualsCanonicalizing($masked, $loaded->result->maskedProviderResponse);
    }

    public function test_find_latest_returns_the_most_recent_by_id(): void
    {
        $prospectId = $this->makeProspect();
        $at = new DateTimeImmutable('2026-08-22 12:00:00');

        $this->repository()->save($prospectId, null, $this->verifiedResult('VER-OLD'), $at);
        $newest = $this->repository()->save($prospectId, null, $this->verifiedResult('VER-NEW'), $at);

        $loaded = $this->repository()->findLatestFor($prospectId);

        $this->assertNotNull($loaded);
        $this->assertSame($newest->id, $loaded->id);
        $this->assertSame('VER-NEW', $loaded->result->verificationFolio);
    }

    public function test_find_latest_returns_null_when_there_is_no_row(): void
    {
        $prospectId = $this->makeProspect();

        $this->assertNull($this->repository()->findLatestFor($prospectId));
    }
}
