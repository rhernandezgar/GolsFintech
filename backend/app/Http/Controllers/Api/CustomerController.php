<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCase\Customer\LookupCustomer;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditContext;
use App\Domain\Shared\Folio;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\CreditApplicationRecord;
use App\Infrastructure\Persistence\Eloquent\CustomerRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * P7: consulta administrativa del cliente por su numero (RF-11).
 *
 * Es el UNICO endpoint del bloque 6 que consume un rol administrativo y no
 * el token de prospecto. El middleware `can:customer.view.any` corta el paso
 * a prospecto y a cliente —cuyo alcance es solo lo suyo— y admite admin,
 * auditor y analista de riesgos.
 *
 * ### Autorizacion por CAMPO, no por endpoint (RS-05)
 *
 * `declared_income` es un dato restringido en la Fase 2 §P7 al analista de
 * riesgos —la unica figura administrativa con necesidad justificada de
 * conocerlo—. La respuesta lleva TODO lo demas para todos los roles con
 * `ViewAnyCustomer`; el ingreso se anade solo cuando el usuario tambien
 * tiene `ViewAnyDeclaredIncome`. Negar el recurso entero por ese campo
 * obligaria a duplicar endpoints por rol, y ocultarlo solo en la interfaz
 * no seria un control.
 *
 * ### El acceso es en si mismo auditable (RS-06.a)
 *
 * `LookupCustomer` emite `customer.looked_up` con actor, IP y numero de
 * cliente cuando encuentra, y `auth.authorization_denied` con
 * `reason=customer_not_found` cuando no encuentra —lo que un barrido por
 * numero delataria como riesgo R-01—.
 */
final class CustomerController extends Controller
{
    public function __construct(
        private readonly LookupCustomer $lookupCustomer,
    ) {}

    public function show(Request $request, string $customerNumber): JsonResponse
    {
        try {
            $folio = Folio::fromString($customerNumber);
        } catch (InvalidArgumentException) {
            // Numero mal formado: 404 generico. No revela nada del catalogo
            // real de clientes.
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $customer = $this->lookupCustomer->execute(
                $folio,
                new AuditContext(actor: 'user:'.$request->user()->id, ipAddress: $request->ip()),
                now()->toImmutable(),
            );
        } catch (RuntimeException) {
            // Cliente no encontrado. El use case ya dejo
            // auth.authorization_denied con reason=customer_not_found en la
            // bitacora; al cliente se le devuelve 404 sin detalle: revelar la
            // existencia haria del numero un identificador enumerable.
            abort(Response::HTTP_NOT_FOUND);
        }

        $data = $customer->toArray();

        // Filtrado por campo (RS-05): el ingreso declarado solo viaja al
        // rol con permiso explicito. Se lee de credit_applications, que es
        // donde vive el validated_monthly_income tras la aprobacion.
        if ($request->user()->hasPermission(Permission::ViewAnyDeclaredIncome)) {
            $data['declared_income'] = $this->declaredIncomeFor($customer->customerId);
        }

        return new JsonResponse(['data' => $data]);
    }

    private function declaredIncomeFor(int $customerId): ?string
    {
        $applicationId = CustomerRecord::query()->where('id', $customerId)->value('credit_application_id');

        if ($applicationId === null) {
            return null;
        }

        $income = CreditApplicationRecord::query()
            ->where('id', $applicationId)
            ->value('validated_monthly_income');

        return $income === null ? null : (string) $income;
    }
}
