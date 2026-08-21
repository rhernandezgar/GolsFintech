<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * Raiz de todas las excepciones del dominio.
 *
 * Se extiende RuntimeException (SPL, no del framework) a proposito: la capa Domain
 * no puede depender de Laravel (regla de dependencia hexagonal, CLAUDE.md seccion 3).
 *
 * Cada excepcion lleva dos mensajes separados:
 *   - getMessage(): detalle tecnico, solo para los registros del servidor.
 *   - userMessage(): mensaje generico en espanol para el usuario final, sin nombres
 *     de tabla, columna ni traza (regla de seguridad 8 y VUL-05).
 * errorCode() es el codigo estable con el que la API correlaciona el fallo.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function errorCode(): string;

    abstract public function userMessage(): string;
}
