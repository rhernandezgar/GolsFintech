<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCase\Prospect\StartProspectCapture;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Port\AuditLogger;
use App\Domain\Prospect\CaptureMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Prospect\StartProspectCaptureRequest;
use App\Infrastructure\Security\CaptchaVerifier;
use App\Infrastructure\Security\ProspectSessionIssuer;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1: inicio de la solicitud y sesion del prospecto.
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
 */
final class ProspectController extends Controller
{
    public function __construct(
        private readonly StartProspectCapture $startProspectCapture,
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

    /** Estado del propio expediente. La SPA lo usa para saber por que paso va. */
    public function show(Request $request): JsonResponse
    {
        $record = $request->user()->prospect;

        if ($record === null) {
            return new JsonResponse(['message' => 'No hay una solicitud asociada a esta sesion.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => [
            'tracking_id' => $record->public_id,
            'capture_method' => $record->capture_method,
            'capture_status' => $record->capture_status,
            // Sin CURP ni RFC, ni siquiera enmascarados: para saber por que
            // paso va el tramite no hace falta ningun dato personal.
            'has_data' => $record->full_name !== null,
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
}
