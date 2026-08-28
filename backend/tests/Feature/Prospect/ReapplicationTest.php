<?php

declare(strict_types=1);

namespace Tests\Feature\Prospect;

use App\Domain\Audit\AuditEventType;
use App\Infrastructure\Persistence\Eloquent\AuditLogRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Infrastructure\Security\PiiHasher;
use Database\Seeders\OAuthPersonalAccessClientSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Una CURP, una solicitud ACTIVA a la vez», por HTTP (VUL-17).
 *
 * `ReapplicationPolicyTest` fija las reglas sobre el dominio puro. Esto
 * comprueba que llegan al cliente: el codigo de estado, el `error_code` que
 * distingue cada caso y —lo que fallaba— que el mensaje **no** le dice a alguien
 * con una CURP correcta que su CURP no es valida.
 */
final class ReapplicationTest extends TestCase
{
    use RefreshDatabase;

    /** CURP valida y estable para todo el archivo. */
    private const CURP = 'HEGR791216HTCRRG09';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OAuthPersonalAccessClientSeeder::class);
    }

    private function newSessionToken(): string
    {
        return (string) $this->postJson('/api/v1/prospects', [
            'capture_method' => 'manual',
            'privacy_notice_accepted' => true,
            'captcha_token' => 'captcha-ok',
        ])->assertCreated()->json('data.session.access_token');
    }

    private function capture(string $token, string $curp = self::CURP): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/prospects/me', [
                'full_name' => 'Rogelio Hernandez Garcia',
                'curp' => $curp,
                'age' => 46,
                'sex' => 'H',
                'monthly_income' => '30000.00',
            ]);
    }

    /** Envejece el expediente para simular inactividad sin viajar en el tiempo. */
    private function ageLastActivity(string $interval): ProspectRecord
    {
        $record = ProspectRecord::query()->latest('id')->firstOrFail();
        $record->timestamps = false;
        $record->updated_at = now()->sub($interval);
        $record->save();

        return $record;
    }

    // ============================== en curso, NO expirada ===================

    #[Test]
    public function a_live_application_blocks_the_second_one_without_calling_the_curp_invalid(): void
    {
        $this->capture($this->newSessionToken())->assertOk();

        $response = $this->capture($this->newSessionToken())->assertStatus(422);

        $response->assertJsonPath('error_code', 'PROSPECT_APPLICATION_IN_PROGRESS');

        // El defecto de VUL-17 en una linea: la CURP es valida y el mensaje
        // decia que no lo era, mandando al solicitante a revisarla en bucle.
        $this->assertStringNotContainsString('no es valida', $response->json('message'));
        $this->assertStringNotContainsString('Revisala', $response->json('message'));
        // Y lleva minutos reales, no un «intentalo mas tarde».
        $this->assertMatchesRegularExpression('/\d+ minuto/', $response->json('message'));
    }

    // ================================ en curso, EXPIRADA ====================

    #[Test]
    public function an_expired_application_lets_a_new_one_through(): void
    {
        $this->capture($this->newSessionToken())->assertOk();
        $expired = $this->ageLastActivity('11 minutes');

        $this->capture($this->newSessionToken())->assertOk();

        $this->assertSame('abandoned', $expired->fresh()->capture_status);
    }

    #[Test]
    public function abandoning_an_expired_application_leaves_its_trace(): void
    {
        // RS-09: un expediente con datos personales no cambia de estado sin que
        // se pueda acreditar cuando y por que.
        $this->capture($this->newSessionToken())->assertOk();
        $expired = $this->ageLastActivity('11 minutes');

        $this->capture($this->newSessionToken())->assertOk();

        $event = AuditLogRecord::query()
            ->where('prospect_id', $expired->id)
            ->where('event_type', AuditEventType::ProspectAbandoned->value)
            ->first();

        $this->assertNotNull($event, 'El abandono por caducidad tiene que quedar en la bitacora.');
        $this->assertSame('in_progress_window_expired', $event->metadata['reason'] ?? null);
        // Sin CURP en el evento (regla 1).
        $this->assertStringNotContainsString(self::CURP, json_encode($event->metadata));
    }

    #[Test]
    public function the_window_comes_from_configuration(): void
    {
        config(['security.reapplication.in_progress_window_minutes' => 60]);

        $this->capture($this->newSessionToken())->assertOk();
        $this->ageLastActivity('11 minutes');

        // Con la ventana en 60 minutos, 11 de inactividad ya NO liberan.
        $this->capture($this->newSessionToken())
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PROSPECT_APPLICATION_IN_PROGRESS');
    }

    // ======================================= ya es cliente ==================

    #[Test]
    public function a_curp_that_belongs_to_a_customer_gets_its_own_message(): void
    {
        // El caso llega hasta el final del recorrido, asi que se construye
        // sobre el estado real y no fabricando filas a mano.
        $token = $this->newSessionToken();
        $this->capture($token)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/prospects/me/confirm')->assertOk();

        $document = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/identity-documents', [
                'document' => UploadedFile::fake()->image('ine.jpg', 600, 400),
                'document_type' => 'INE',
            ], ['Accept' => 'application/json'])->assertStatus(202)->json('data.tracking_id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/identity-validations', ['document_public_id' => $document])
            ->assertOk()->assertJsonPath('data.status', 'verified');

        $simulation = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/credit-simulations', ['term_months' => 12])
            ->assertCreated()->json('data.simulation_public_id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/credit-simulations/'.$simulation.'/accept')->assertOk();

        // Ya es cliente. Otra solicitud con la misma CURP no espera diez
        // minutos: espera para siempre, y por eso el mensaje es distinto.
        $response = $this->capture($this->newSessionToken())->assertStatus(422);

        $response->assertJsonPath('error_code', 'PROSPECT_ALREADY_CUSTOMER');
        $this->assertStringNotContainsString('minuto', $response->json('message'));
        $this->assertStringContainsString('cliente', $response->json('message'));
    }

    // =========================================== el propio expediente =======

    #[Test]
    public function rewriting_the_same_curp_in_the_same_file_is_not_a_duplicate(): void
    {
        // Corregir una letra y volver a guardar es lo que hace cualquiera.
        $token = $this->newSessionToken();

        $this->capture($token)->assertOk();
        $this->capture($token)->assertOk();
    }

    // ================================ la restriccion en la base =============

    /**
     * La regla no vive solo en `ReapplicationPolicy`: el esquema la impone.
     *
     * Antes, el UNIQUE sobre `curp_hash` ERA la regla vieja escrita en la base,
     * y aunque la politica autorizara la nueva solicitud el INSERT chocaba con
     * el indice y salia un 500. Que las dos digan lo mismo es la propiedad que
     * se fija aqui.
     */
    #[Test]
    public function the_database_allows_many_abandoned_files_but_only_one_live_per_curp(): void
    {
        $this->capture($this->newSessionToken())->assertOk();
        $this->ageLastActivity('11 minutes');
        $this->capture($this->newSessionToken())->assertOk();
        $this->ageLastActivity('11 minutes');
        $this->capture($this->newSessionToken())->assertOk();

        $hash = app(PiiHasher::class)->hash(self::CURP);
        $withCurp = ProspectRecord::query()->where('curp_hash', $hash)->get();

        // Tres expedientes con la misma CURP conviven...
        $this->assertCount(3, $withCurp);
        // ...y exactamente uno esta vivo.
        $this->assertCount(
            1,
            $withCurp->where('capture_status', '!=', 'abandoned'),
            'La base tiene que dejar como maximo una solicitud activa por CURP.'
        );
    }

    #[Test]
    public function the_unique_index_still_stops_a_second_live_file_written_directly(): void
    {
        // Sin pasar por la politica: es la defensa que queda si una condicion
        // de carrera colara dos capturas simultaneas.
        $this->capture($this->newSessionToken())->assertOk();
        $live = ProspectRecord::query()->latest('id')->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        ProspectRecord::query()->create([
            'public_id' => (string) Str::uuid(),
            'full_name' => 'Otro Solicitante',
            'capture_method' => 'manual',
            'capture_status' => 'data_captured',
            'curp_hash' => $live->curp_hash,
            'monthly_income' => 30000.00,
        ]);
    }
}
