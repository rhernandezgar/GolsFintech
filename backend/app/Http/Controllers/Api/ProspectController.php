<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTO\ProspectDataPatch;
use App\Application\UseCase\Prospect\ConfirmProspectData;
use App\Application\UseCase\Prospect\StartProspectCapture;
use App\Application\UseCase\Prospect\UpdateProspectDraft;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\ProspectDataIncompleteException;
use App\Domain\Identity\ExtractedIdentityData;
use App\Domain\Identity\OcrStatus;
use App\Domain\Port\AuditLogger;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Shared\Uuid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Prospect\CaptureProspectDataRequest;
use App\Http\Requests\Prospect\ConfirmProspectDataRequest;
use App\Http\Requests\Prospect\StartProspectCaptureRequest;
use App\Infrastructure\Persistence\Eloquent\IdentityDocumentRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Infrastructure\Security\CaptchaVerifier;
use App\Infrastructure\Security\ProspectSessionIssuer;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1 (nace la sesion), P2 (captura y confirmacion de datos) y sesion.
 *
 * `store()` es el UNICO endpoint del recorrido del prospecto sin autenticar, y
 * lo es por necesidad: es donde nace la credencial. Su justificacion completa
 * esta en la cabecera de routes/api.php, junto con las otras cuatro excepciones
 * de la regla de seguridad no negociable 9.
 *
 * A cambio lleva dos controles que las rutas autenticadas no necesitan:
 * limitacion de peticiones por IP y CAPTCHA. Son complementarios, no
 * redundantes: el throttle cuenta por direccion, y repartir el trabajo entre
 * muchas direcciones lo esquiva sin esfuerzo (RS-10, riesgo R-05).
 *
 * `update()` y `confirm()` sirven P2. El expediente sale del token, no de la
 * URL: no hay forma de nombrar el expediente de otro (CWE-639 cerrado por
 * diseno de rutas). Aun asi cada uno aplica `ProspectPolicy::updateOwn` como
 * defensa en profundidad: si manana apareciera una ruta con `{prospect}` la
 * comprobacion de titularidad quedaria en el mismo lugar.
 */
final class ProspectController extends Controller
{
    public function __construct(
        private readonly StartProspectCapture $startProspectCapture,
        private readonly UpdateProspectDraft $updateProspectDraft,
        private readonly ConfirmProspectData $confirmProspectData,
        private readonly ProspectSessionIssuer $sessions,
        private readonly CaptchaVerifier $captcha,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(StartProspectCaptureRequest $request): JsonResponse
    {
        $input = $request->validated();

        if (! $this->captcha->verify($input['captcha_token'], $request->ip())) {
            // Se audita el rechazo: un pico de estos es la senal de que alguien
            // esta intentando crear expedientes en masa (riesgo R-05).
            $this->auditLogger->append(new AuditEvent(
                eventType: AuditEventType::AuthorizationDenied,
                affectedEntity: 'Prospect',
                affectedEntityId: null,
                prospectId: null,
                context: new AuditContext(actor: 'anonymous', ipAddress: $request->ip()),
                eventAt: new DateTimeImmutable,
                metadata: ['reason' => 'captcha_failed'],
            ));

            return new JsonResponse([
                'message' => 'No pudimos verificar que la solicitud viene de una persona. Intentalo de nuevo.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $prospect = $this->startProspectCapture->execute(
            captureMethod: CaptureMethod::from($input['capture_method']),
            privacyNoticeVersion: (string) config('security.privacy_notice.version'),
            context: new AuditContext(actor: 'anonymous', ipAddress: $request->ip()),
            now: new DateTimeImmutable,
        );

        $session = $this->sessions->openSessionFor($prospect);

        return new JsonResponse([
            'data' => [
                'tracking_id' => $prospect->publicId()->value,
                'capture_method' => $prospect->captureMethod()->value,
                'capture_status' => $prospect->captureStatus()->value,
                'privacy_notice_version' => (string) config('security.privacy_notice.version'),
                'session' => $session->toArray(),
            ],
            'message' => 'Solicitud iniciada.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Estado del propio expediente, mas lo que el OCR extrajo del documento.
     *
     * LOS DATOS EXTRAIDOS VIAJAN PORQUE EL DISENO LOS EXIGE. El prototipo de la
     * Fase 2 muestra en P3 los datos detectados con su estado de legibilidad, y
     * la Fase 3 pide que se presenten SIEMPRE para confirmacion humana, porque
     * el OCR puede errar. Sin ellos, el prospecto de la rama OCR confirma a
     * ciegas y una extraccion equivocada entra al expediente sin que nadie la
     * mire.
     *
     * Lo que NO viaja es el dato completo cuando es sensible: `ExtractedIdentityData`
     * enmascara CURP, RFC y numero de documento **antes** de que salgan del
     * dominio, conservando lo justo para reconocerlos (RS-03). El enmascarado
     * es parcial y no `[REDACTED]` a proposito: con el dato borrado entero la
     * pantalla de revision no sirve para revisar nada.
     *
     * Los campos del expediente ya capturado siguen sin viajar: para saber por
     * que paso va el tramite no hace falta ningun dato personal, y `has_data`
     * lo resuelve.
     */
    public function show(Request $request): JsonResponse
    {
        $record = $request->user()->prospect;

        if ($record === null) {
            return new JsonResponse(['message' => 'No hay una solicitud asociada a esta sesion.'], Response::HTTP_NOT_FOUND);
        }

        $data = [
            'tracking_id' => $record->public_id,
            'capture_method' => $record->capture_method,
            'capture_status' => $record->capture_status,
            'has_data' => $record->full_name !== null,
        ];

        $extraction = $this->latestExtractionFor($record);

        if ($extraction !== null) {
            $data['ocr_extraction'] = $extraction;
        }

        return new JsonResponse(['data' => $data]);
    }

    /**
     * Ultima extraccion completada del prospecto, ya proyectada y enmascarada.
     *
     * Se toma del documento y no de `prospects` porque es ahi donde vive: el
     * worker escribe `ocr_result` sobre `identity_documents` y no copia nada al
     * expediente. Solo se consideran documentos en estado final `completed`:
     * uno en proceso no tiene resultado, y uno fallido no tiene nada que
     * revisar.
     *
     * @return array<string, mixed>|null
     */
    private function latestExtractionFor(ProspectRecord $record): ?array
    {
        $document = IdentityDocumentRecord::query()
            ->where('prospect_id', $record->id)
            ->where('ocr_status', OcrStatus::Completed->value)
            ->latest('processed_at')
            ->first();

        if ($document === null) {
            return null;
        }

        $extracted = ExtractedIdentityData::fromOcrResult(
            is_array($document->ocr_result) ? $document->ocr_result : null,
        );

        if ($extracted === null) {
            return null;
        }

        return $extracted->toArray() + [
            'document_tracking_id' => $document->public_id,
            'processed_at' => $document->processed_at?->toIso8601String(),
        ];
    }

    /**
     * P2 (PATCH): actualizacion parcial. Acepta cualquier subconjunto de
     * campos del formulario; la comprobacion de expediente completo la hace
     * `confirm()`, no este endpoint.
     */
    public function update(CaptureProspectDataRequest $request): JsonResponse
    {
        $prospect = $this->ownProspectOrFail($request);

        try {
            $updated = $this->updateProspectDraft->execute(
                Uuid::fromString($prospect->public_id),
                new ProspectDataPatch(
                    fullName: $request->input('full_name'),
                    curp: $request->input('curp'),
                    rfc: $request->input('rfc'),
                    age: $request->has('age') ? (int) $request->input('age') : null,
                    sex: $request->input('sex'),
                    monthlyIncome: $request->input('monthly_income'),
                    address: $request->input('address'),
                    geographicLocation: $request->input('geographic_location'),
                    businessType: $request->input('business_type'),
                    email: $request->input('email'),
                    phone: $request->input('phone'),
                ),
                new AuditContext(actor: 'prospect:'.$prospect->public_id, ipAddress: $request->ip()),
                new DateTimeImmutable,
            );
        } catch (DomainException $e) {
            // Cualquier fallo del dominio —CURP con digito equivocado, CURP
            // duplicada, RFC invalido, telefono mal formado— se traduce a 422
            // con el codigo estable de la excepcion. El detalle tecnico queda
            // en el mensaje interno de la excepcion, para el registro del
            // servidor, y no viaja al cliente (regla de seguridad 8, VUL-05).
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => [
            'tracking_id' => $updated->publicId()->value,
            'capture_status' => $updated->captureStatus()->value,
            // Sin datos personales: el cliente ya sabe lo que envio; devolver
            // el CURP aunque sea propio agranda la superficie sin necesidad.
            'has_data' => $updated->fullName() !== null,
        ]]);
    }

    /**
     * P2 (paso 2): confirmacion. Exige el expediente completo. Si faltan
     * campos obligatorios, responde 422 indicando cuales.
     */
    public function confirm(ConfirmProspectDataRequest $request): JsonResponse
    {
        $prospect = $this->ownProspectOrFail($request);

        try {
            $confirmed = $this->confirmProspectData->execute(
                Uuid::fromString($prospect->public_id),
                new AuditContext(actor: 'prospect:'.$prospect->public_id, ipAddress: $request->ip()),
                new DateTimeImmutable,
            );
        } catch (ProspectDataIncompleteException $e) {
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
                'missing_fields' => $e->missingFields(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => [
            'tracking_id' => $confirmed->publicId()->value,
            'capture_status' => $confirmed->captureStatus()->value,
        ]]);
    }

    /**
     * Renovacion silenciosa mientras haya actividad.
     *
     * Emite un token nuevo y no revoca el anterior: P3 consulta el estado del
     * OCR en bucle y revocar en caliente dejaria sin credencial a la peticion
     * que ya iba por el cable. Cada token caduca por su cuenta dentro de su
     * ventana de 30 minutos, asi que el limite se sigue cumpliendo.
     */
    public function renewSession(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => [
            'session' => $this->sessions->renew($request->user())->toArray(),
        ]]);
    }

    /**
     * Resuelve el expediente desde el token y aplica la politica. La ruta ya
     * exige `scopes:prospect-session`, asi que si llegamos aqui sin prospecto
     * asignado es un usuario mal formado —el flujo de P1 siempre asigna uno—.
     */
    private function ownProspectOrFail(Request $request): ProspectRecord
    {
        $prospect = $request->user()->prospect;

        if (! $prospect instanceof ProspectRecord) {
            // Mensaje generico; el detalle queda en el registro del servidor.
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a este recurso.');
        }

        Gate::authorize('updateOwn', $prospect);

        return $prospect;
    }
}
