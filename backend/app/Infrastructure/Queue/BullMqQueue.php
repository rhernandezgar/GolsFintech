<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use JsonException;

/**
 * Productor de trabajos de BullMQ desde PHP.
 *
 * El backend encola y el worker de Node consume (Fase 2 §d, Figura 2a). Como los
 * dos procesos hablan por Redis, alguien tiene que escribir en el formato del
 * otro: aqui es PHP quien escribe el formato de BullMQ, y no al reves, porque el
 * worker consume con la libreria de verdad y no conviene que reimplemente nada.
 *
 * ACOPLAMIENTO EXPLICITO, y la unica forma sensata de sostenerlo:
 * este esquema de claves es interno de BullMQ y puede cambiar entre versiones
 * mayores. Se verifico observando lo que escribe `Queue.add` en bullmq 6.1.2, no
 * leyendo documentacion. Lo que impide que una actualizacion lo rompa en
 * silencio es `BullMqContractTest`, que encola desde PHP y hace que un `Worker`
 * de BullMQ de verdad lo consuma: si el formato cambia, esa prueba se pone roja
 * antes de que se pierda ningun trabajo en produccion.
 *
 * Claves que BullMQ 6.1.2 usa para un trabajo estandar sin retardo:
 *   <prefijo>:<cola>:id       contador (INCR) del que sale el identificador
 *   <prefijo>:<cola>:<id>     hash con name, data, opts, timestamp, delay, priority
 *   <prefijo>:<cola>:wait     lista de pendientes (LPUSH)
 *   <prefijo>:<cola>:marker   zset que despierta al worker que espera
 *   <prefijo>:<cola>:meta     configuracion de la cola
 *   <prefijo>:<cola>:events   stream de eventos (added, waiting)
 */
final readonly class BullMqQueue
{
    /**
     * Se hace en un script Lua y no en varias ordenes sueltas porque debe ser
     * atomico: un trabajo con hash pero sin entrada en `wait` no lo procesa
     * nadie, y uno en `wait` sin hash rompe al worker. Redis ejecuta el script
     * entero sin intercalar nada.
     */
    private const ADD_JOB_SCRIPT = <<<'LUA'
        local jobId = redis.call("INCR", KEYS[1])
        local jobKey = KEYS[6] .. jobId

        redis.call("HSET", jobKey,
            "name", ARGV[1],
            "data", ARGV[2],
            "opts", ARGV[3],
            "timestamp", ARGV[4],
            "delay", 0,
            "priority", 0)

        -- HSETNX: no pisar la configuracion que el worker haya fijado ya.
        redis.call("HSETNX", KEYS[4], "opts.maxLenEvents", ARGV[5])

        redis.call("LPUSH", KEYS[2], jobId)

        -- El marcador es lo que saca del bloqueo a un worker inactivo. Sin el,
        -- el trabajo se queda en `wait` hasta que algo mas despierte al worker.
        redis.call("ZADD", KEYS[3], 0, "0")

        redis.call("XADD", KEYS[5], "MAXLEN", "~", ARGV[5], "*",
            "event", "added", "jobId", jobId, "name", ARGV[1])
        redis.call("XADD", KEYS[5], "MAXLEN", "~", ARGV[5], "*",
            "event", "waiting", "jobId", jobId)

        return jobId
        LUA;

    public function __construct(
        private RedisFactory $redis,
        private string $connection = 'bullmq',
        private string $prefix = 'bull',
        private int $maxLenEvents = 10000,
    ) {}

    /**
     * Encola un trabajo y devuelve el identificador que le asigno BullMQ.
     *
     * @param  array<string, mixed>  $data  carga util; nunca datos personales
     * @param  array<string, mixed>  $options  opciones de BullMQ (attempts, backoff...)
     *
     * @throws JsonException si la carga no es serializable
     */
    public function add(string $queue, string $jobName, array $data, array $options = []): string
    {
        $base = sprintf('%s:%s:', $this->prefix, $queue);

        $jobId = $this->redis->connection($this->connection)->eval(
            self::ADD_JOB_SCRIPT,
            6,
            $base.'id',
            $base.'wait',
            $base.'marker',
            $base.'meta',
            $base.'events',
            $base,
            $jobName,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode((object) $options, JSON_THROW_ON_ERROR),
            (string) (int) (microtime(true) * 1000),
            (string) $this->maxLenEvents,
        );

        return (string) $jobId;
    }
}
