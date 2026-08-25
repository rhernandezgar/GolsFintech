<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identificador de correlacion por peticion (VUL-05, regla de seguridad 8).
 *
 * ### El problema que resuelve
 *
 * La regla 8 obliga a que el cliente reciba un mensaje generico y el detalle
 * tecnico se quede en el servidor. Cumplida a secas, deja a soporte sin nada:
 * el usuario llama diciendo «me sale que no se pudo completar la operacion» y no
 * hay forma de encontrar CUAL de las miles de lineas del registro es la suya.
 * En la practica eso empuja a lo contrario de lo que la regla quiere: alguien
 * acaba devolviendo el detalle «solo por esta vez» para poder depurar.
 *
 * El identificador de correlacion cierra esa tension. Es **opaco y sin
 * contenido**: un ULID que no dice nada del fallo, no revela si el recurso
 * existe y no sirve como credencial. Lo unico que hace es unir la respuesta que
 * vio el usuario con la linea del registro que la explica.
 *
 * ### Por que se genera aqui y no se acepta del cliente
 *
 * Seria comodo respetar un `X-Correlation-Id` entrante, y es lo que hacen
 * muchas pasarelas. **No se hace**: un valor controlado por el cliente entra
 * directo a los registros, y eso es inyeccion de registro —saltos de linea que
 * fabrican entradas falsas, secuencias que confunden al agregador, valores
 * enormes que llenan el disco—. Ademas, dos peticiones podrian declarar el mismo
 * identificador y romper justo la propiedad por la que existe. El servidor lo
 * genera, y punto.
 *
 * ### Por que es middleware y no solo un `render()` de excepciones
 *
 * Porque no todas las respuestas de error pasan por el manejador de
 * excepciones: los controladores del recorrido construyen sus 422 y 403 con
 * `new JsonResponse(...)` directamente, y esas nunca lanzan nada. Un
 * `renderable()` las dejaria sin identificador y la normalizacion tendria
 * agujeros justo en los endpoints mas usados. Aqui se cubre **toda** respuesta
 * de error, venga de donde venga.
 */
final class AssignCorrelationId
{
    /** Clave con la que el resto de la aplicacion recupera el identificador. */
    public const ATTRIBUTE = 'correlation_id';

    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        // ULID y no UUID v4: ordenable por tiempo, asi que quien busca en el
        // registro puede acotar por rango en vez de recorrerlo entero.
        $correlationId = (string) Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $correlationId);

        $response = $next($request);

        // La cabecera va en TODA respuesta, no solo en las de error: permite
        // correlacionar tambien una peticion que respondio 200 y se comporto
        // raro, que es un caso de soporte tan real como el fallo.
        $response->headers->set(self::HEADER, $correlationId);

        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $this->addCorrelationIdTo($response, $correlationId);
        }

        return $response;
    }

    /**
     * Anade `correlation_id` al cuerpo sin tocar nada mas.
     *
     * No se reescribe el resto de la respuesta: los controladores ya emiten
     * `message` y `error_code` pensados uno por uno, y normalizar por encima
     * los sustituiria por algo mas pobre. Lo que faltaba era el hilo con el
     * registro, y es lo unico que se anade.
     */
    private function addCorrelationIdTo(JsonResponse $response, string $correlationId): void
    {
        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return;
        }

        // Si ya lo trae -por ejemplo porque el manejador de excepciones lo
        // puso-, no se pisa.
        if (array_key_exists('correlation_id', $payload)) {
            return;
        }

        $payload['correlation_id'] = $correlationId;

        $response->setData($payload);
    }
}
