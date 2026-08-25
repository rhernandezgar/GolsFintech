<?php

declare(strict_types=1);

namespace Tests\Feature\Journey;

use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use Database\Seeders\OAuthPersonalAccessClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Las DOS rutas del diseno, de extremo a extremo y **sobre HTTP**.
 *
 * ### Por que este archivo existe (VUL-15)
 *
 * VUL-15 fue un 500 en la rama manual: subir la identificacion despues de
 * confirmar los datos lanzaba una transicion de estado prohibida. Cuatrocientas
 * cuarenta y tres pruebas no lo vieron, y las razones son dos y conviene
 * tenerlas separadas:
 *
 *   1. **`DocumentUploadTest` solo fabrica prospectos en `started`**, que es el
 *      orden de la rama OCR —documento primero, datos despues—. El estado
 *      `data_confirmed`, por el que pasa la rama manual, no lo ejercia nadie.
 *   2. **`ProspectJourneyTest` no toca HTTP**: invoca los casos de uso
 *      directamente. Cubre muy bien el encadenamiento del dominio y por
 *      construccion no puede ver un defecto que depende del ORDEN en que las
 *      pantallas llaman a la API.
 *
 * Entre las dos quedaba un hueco con forma exacta de VUL-15: el recorrido
 * completo, en el orden real, por la superficie real. Este archivo lo cierra, y
 * lo hace **con el par**: manual y OCR. Una sola no bastaria, porque el defecto
 * consistio precisamente en arreglar una rama y romper la otra sin ejercerla.
 *
 * ### Las dos rutas no son la misma con los pasos barajados
 *
 *   - **OCR**: P1 -> P3 (el documento CAPTURA los datos) -> P2 (confirmar lo
 *     extraido) -> P4 -> P5 -> P6.
 *   - **Manual**: P1 -> P2 (escribir y confirmar) -> P3 (el documento es la
 *     EVIDENCIA que P4 necesita) -> P4 -> P5 -> P6.
 *
 * En la primera, el documento llega con el expediente en `started`. En la
 * segunda, en `data_confirmed`. Es el mismo endpoint recibiendo dos estados
 * distintos, y ahi vivia el defecto.
 */
final class FullFlowOverHttpTest extends TestCase
{
    use RefreshDatabase;

    private const CAPTCHA = 'captcha-ok';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OAuthPersonalAccessClientSeeder::class);
        Storage::fake('local');
    }

    /** P1. Devuelve el token de la sesion del prospecto. */
    private function startCapture(string $method): string
    {
        return (string) $this->postJson('/api/v1/prospects', [
            'capture_method' => $method,
            'privacy_notice_accepted' => true,
            'captcha_token' => self::CAPTCHA,
        ])->assertCreated()->json('data.session.access_token');
    }

    /** P3. Devuelve el identificador de seguimiento del documento. */
    private function uploadDocument(string $token): string
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/identity-documents', [
                'document' => UploadedFile::fake()->image('ine.jpg', 600, 400),
                'document_type' => 'INE',
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        return (string) $response->json('data.tracking_id');
    }

    /** P2, primer paso: captura. */
    private function captureData(string $token, string $curp): void
    {
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/prospects/me', [
                'full_name' => 'Ana Perez Lopez',
                'curp' => $curp,
                'age' => 34,
                'sex' => 'M',
                'monthly_income' => '28000.00',
            ])->assertOk();
    }

    private function confirmData(string $token): void
    {
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/prospects/me/confirm')
            ->assertOk()
            ->assertJsonPath('data.capture_status', 'data_confirmed');
    }

    /** P4. Exige documento para que el veredicto sea `verified`. */
    private function validateIdentity(string $token, string $documentPublicId): void
    {
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/identity-validations', ['document_public_id' => $documentPublicId])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');
    }

    /** P5. Devuelve el identificador publico de la simulacion. */
    private function simulate(string $token): string
    {
        return (string) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/credit-simulations', ['term_months' => 12])
            ->assertCreated()
            ->json('data.simulation_public_id');
    }

    /**
     * P6. Devuelve el token de CLIENTE y comprueba la transicion: el de
     * prospecto queda revocado y el nuevo llega con el alcance nuevo.
     */
    private function acceptOffer(string $prospectToken, string $simulationId): string
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$prospectToken)
            ->postJson('/api/v1/credit-simulations/'.$simulationId.'/accept')
            ->assertOk();

        $customerToken = (string) $response->json('data.session.access_token');

        $this->assertSame('customer-session', $response->json('data.session.scope'));
        $this->assertNotEmpty($response->json('data.customer.customer_number'));

        // El token de prospecto muere aqui: dejarlo vivo seria una escalada
        // silenciosa hacia endpoints que no existian cuando se emitio.
        $this->withHeader('Authorization', 'Bearer '.$prospectToken)
            ->getJson('/api/v1/me')->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.$customerToken)
            ->getJson('/api/v1/me')->assertOk();

        return $customerToken;
    }

    // ===================================================== RAMA MANUAL ======

    /**
     * P1 -> P2 -> confirmar -> P3 -> P4 -> P5 -> P6.
     *
     * Es el recorrido que VUL-15 rompia: el documento llega con el expediente
     * ya en `data_confirmed`. Antes de la correccion, el paso de P3 respondia
     * 500.
     */
    #[Test]
    public function the_manual_route_runs_end_to_end_over_http(): void
    {
        $token = $this->startCapture('manual');

        $this->captureData($token, 'HEGG560427MVZRRL04');
        $this->confirmData($token);

        // El estado con el que P3 recibe la carga en esta rama, y que ninguna
        // otra prueba ejercia.
        $prospect = ProspectRecord::query()->latest('id')->firstOrFail();
        $this->assertSame('data_confirmed', $prospect->capture_status);

        $documentId = $this->uploadDocument($token);

        // La carga NO deshace la confirmacion: si retrocediera, P4 responderia
        // 422 y este recorrido se cortaria aqui en silencio.
        $this->assertSame('data_confirmed', $prospect->fresh()->capture_status);

        $this->validateIdentity($token, $documentId);
        $this->acceptOffer($token, $this->simulate($token));
    }

    // ======================================================== RAMA OCR ======

    /**
     * P1 -> P3 -> P2 -> confirmar -> P4 -> P5 -> P6.
     *
     * El orden que si estaba cubierto por partes. Se escribe entero igualmente
     * para que las dos ramas queden como par: arreglar una y romper la otra sin
     * ejercerla es exactamente lo que ocurrio.
     */
    #[Test]
    public function the_ocr_route_runs_end_to_end_over_http(): void
    {
        $token = $this->startCapture('ocr');

        $documentId = $this->uploadDocument($token);

        $prospect = ProspectRecord::query()->latest('id')->firstOrFail();
        $this->assertSame('document_uploaded', $prospect->capture_status);

        $this->captureData($token, 'ROMA910517HDFDRNB7');
        $this->confirmData($token);

        $this->validateIdentity($token, $documentId);
        $this->acceptOffer($token, $this->simulate($token));
    }

    // ================================================ la propiedad comun ====

    /**
     * Las dos ramas convergen en el mismo desenlace. Si una empezara a producir
     * un cliente distinto de la otra, seria una divergencia de negocio y no un
     * detalle de orden.
     */
    #[Test]
    public function both_routes_reach_the_same_kind_of_outcome(): void
    {
        $manualToken = $this->startCapture('manual');
        $this->captureData($manualToken, 'HEGG560427MVZRRL04');
        $this->confirmData($manualToken);
        $manualDocument = $this->uploadDocument($manualToken);
        $this->validateIdentity($manualToken, $manualDocument);

        $manual = $this->withHeader('Authorization', 'Bearer '.$manualToken)
            ->postJson('/api/v1/credit-simulations/'.$this->simulate($manualToken).'/accept')
            ->assertOk()->json('data.customer');

        $ocrToken = $this->startCapture('ocr');
        $ocrDocument = $this->uploadDocument($ocrToken);
        $this->captureData($ocrToken, 'ROMA910517HDFDRNB7');
        $this->confirmData($ocrToken);
        $this->validateIdentity($ocrToken, $ocrDocument);

        $ocr = $this->withHeader('Authorization', 'Bearer '.$ocrToken)
            ->postJson('/api/v1/credit-simulations/'.$this->simulate($ocrToken).'/accept')
            ->assertOk()->json('data.customer');

        // Mismos campos y misma linea activa; los folios y numeros difieren,
        // que es lo que tiene que pasar.
        $this->assertSame(array_keys($manual), array_keys($ocr));
        $this->assertSame('active', $manual['line_status']);
        $this->assertSame('active', $ocr['line_status']);
        $this->assertNotSame($manual['customer_number'], $ocr['customer_number']);
    }
}
