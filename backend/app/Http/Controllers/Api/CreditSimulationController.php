<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCase\Credit\SimulateCredit;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\IdentityNotVerifiedException;
use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Exception\UnauthorizedTermException;
use App\Domain\Shared\Uuid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credit\SimulateCreditRequest;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * P5: simulacion del credito.
 *
 * El prospecto sale del token, siempre. La precondicion "identidad
 * verificada" la impone el use case: un prospecto que quedo en `deferred`
 * (proveedor caido) no puede simular todavia —es distinto de rechazado,
 * pero tampoco es verificado, y consultar el motor de reglas sobre datos
 * sin confirmar RENAPO da una oferta que despues P6 no podria autorizar—.
 */
final class CreditSimulationController extends Controller
{
    public function __construct(
        private readonly SimulateCredit $simulateCredit,
    ) {}

    public function store(SimulateCreditRequest $request): JsonResponse
    {
        $prospect = $request->user()->prospect;

        if (! $prospect instanceof ProspectRecord) {
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a este recurso.');
        }

        try {
            $offer = $this->simulateCredit->execute(
                Uuid::fromString($prospect->public_id),
                (int) $request->input('term_months'),
                new AuditContext(actor: 'prospect:'.$prospect->public_id, ipAddress: $request->ip()),
                new DateTimeImmutable,
            );
        } catch (UnauthorizedTermException|IdentityNotVerifiedException|InvalidStateTransitionException $e) {
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $offer->toArray()], Response::HTTP_CREATED);
    }
}
