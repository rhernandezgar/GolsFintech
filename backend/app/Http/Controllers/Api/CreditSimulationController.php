<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCase\Credit\AcceptCreditOffer;
use App\Application\UseCase\Credit\SimulateCredit;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\CreditSimulationAlreadyDecidedException;
use App\Domain\Exception\CreditSimulationExpiredException;
use App\Domain\Exception\IdentityNotVerifiedException;
use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Exception\UnauthorizedTermException;
use App\Domain\Shared\Uuid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credit\AcceptCreditSimulationRequest;
use App\Http\Requests\Credit\SimulateCreditRequest;
use App\Infrastructure\Persistence\Eloquent\CustomerRecord;
use App\Infrastructure\Persistence\Eloquent\ProspectRecord;
use App\Infrastructure\Security\ProspectSessionIssuer;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * P5 (simulacion) y P6 (aceptacion).
 *
 * El prospecto sale del token, siempre. La precondicion "identidad verificada"
 * en P5 la impone `SimulateCredit`: un prospecto en `deferred` (proveedor
 * caido) no simula todavia —es distinto de rechazado, pero tampoco es
 * verificado—.
 *
 * P6 va sobre `/credit-simulations/{uuid}/accept`. La URL lleva el UUID
 * publico de la simulacion —**es lo que P6 tiene que referenciar**—; el use
 * case cierra la titularidad comprobando que esa simulacion pertenece a la
 * solicitud del prospecto del token (CWE-639). Tres condiciones se comprueban:
 *
 *   1. La simulacion pertenece al prospecto autenticado. Si no, 403.
 *   2. La simulacion no ha caducado. Si caduco, 422 con
 *      `CREDIT_SIMULATION_EXPIRED`.
 *   3. La simulacion no ha sido decidida antes. Si ya se acepto (o rechazo),
 *      422 con `CREDIT_SIMULATION_ALREADY_DECIDED`. Sin este control, un
 *      doble click de la SPA podria crear dos clientes.
 *
 * Al aceptar, el token de prospecto se **revoca** y se emite uno de cliente
 * con el nuevo alcance. Es la quinta precision del bloque 1: dejar vivo el
 * anterior seria una escalada silenciosa. La respuesta trae el token nuevo
 * junto con la vista del cliente.
 */
final class CreditSimulationController extends Controller
{
    public function __construct(
        private readonly SimulateCredit $simulateCredit,
        private readonly AcceptCreditOffer $acceptCreditOffer,
        private readonly ProspectSessionIssuer $sessions,
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
                now()->toImmutable(),
            );
        } catch (UnauthorizedTermException|IdentityNotVerifiedException|InvalidStateTransitionException $e) {
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $offer->toArray()], Response::HTTP_CREATED);
    }

    public function accept(AcceptCreditSimulationRequest $request, string $simulation): JsonResponse
    {
        $user = $request->user();
        $prospect = $user->prospect;

        if (! $prospect instanceof ProspectRecord) {
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a este recurso.');
        }

        try {
            $simulationUuid = Uuid::fromString($simulation);
        } catch (\InvalidArgumentException) {
            // UUID malformado. Se responde generico: no revela si el recurso
            // existe.
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $customer = $this->acceptCreditOffer->execute(
                $simulationUuid,
                Uuid::fromString($prospect->public_id),
                contractVersion: (string) config('security.contract.version'),
                context: new AuditContext(actor: 'prospect:'.$prospect->public_id, ipAddress: $request->ip()),
                now: now()->toImmutable(),
            );
        } catch (CreditSimulationExpiredException|CreditSimulationAlreadyDecidedException|InvalidStateTransitionException $e) {
            return new JsonResponse([
                'message' => $e->userMessage(),
                'error_code' => $e->errorCode(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            // El use case usa RuntimeException generico para "no existe" y
            // "no pertenece al prospecto autenticado" (CWE-639). Se traducen
            // los dos a 403 con mensaje generico: revelar cual de los dos es
            // -no existe vs no le pertenece- le da al atacante la pista de
            // que su intento acerto a un recurso valido.
            return new JsonResponse([
                'message' => 'No tiene acceso a este recurso.',
                'error_code' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $customerRecord = CustomerRecord::query()->findOrFail($customer->customerId);
        $issued = $this->sessions->promoteToCustomer($user, (int) $customerRecord->id);

        return new JsonResponse([
            'data' => [
                'customer' => $customer->toArray(),
                // Token nuevo de cliente. El de prospecto quedo revocado en
                // `promoteToCustomer`; dejar vivo el anterior seria escalada
                // silenciosa.
                'session' => $issued->toArray(),
            ],
        ]);
    }
}
