<?php

declare(strict_types=1);

namespace App\Domain\Prospect;

use DateTimeImmutable;

/**
 * Cuando una CURP puede volver a solicitar credito.
 *
 * ### La restriccion que habia era la equivocada
 *
 * El modelo anterior era «una CURP, una solicitud», impuesto por el UNIQUE de
 * `curp_hash`. Eso convierte cualquier intento fallido en un veto **permanente**:
 * quien abandonaba a mitad del formulario, o a quien el proveedor de identidad
 * no le confirmaba los datos una vez, no podia volver a solicitar nunca. La
 * Fase 1 contempla expresamente el rechazo por identidad no verificada como un
 * desenlace normal del proceso, no como una expulsion.
 *
 * La restriccion correcta es **«una CURP, una solicitud ACTIVA a la vez»**. Lo
 * que se protege es que no haya dos expedientes vivos compitiendo por la misma
 * persona; lo que no se pretende es castigar el primer intento.
 *
 * ### Los cinco desenlaces
 *
 * 1. **No hay nada** -> se permite.
 * 2. **Hay una solicitud viva y reciente** -> se bloquea, diciendo cuantos
 *    minutos faltan. El minutaje es real y calculado, no un texto fijo: un
 *    «intentalo mas tarde» sin numero es lo que hace que la gente reintente
 *    cada pocos segundos.
 * 3. **Hay una solicitud viva pero caducada** -> se abandona y se permite la
 *    nueva. La caducidad no es solo comodidad: RS-09 (LFPDPPP, finalidad y
 *    proporcionalidad) pide que un expediente abandonado no se conserve
 *    indefinidamente con datos personales, y marcarlo es el primer paso.
 * 4. **La CURP ya es de un cliente** -> se bloquea, y con mensaje propio.
 *    Mezclarlo con «tienes una solicitud en curso» mandaria a un cliente ya
 *    dado de alta a esperar diez minutos por algo que no va a cambiar.
 * 5. **Hubo rechazos y son demasiados en la ventana** -> se bloquea.
 *
 * ### Por que el limite de reintentos no es opcional
 *
 * Permitir reintentos ilimitados convierte P4 en un **oraculo de fuerza bruta
 * contra INE y RENAPO**: con la CURP como unica entrada, alguien puede sondear
 * combinaciones de nombre, fecha o sexo hasta que una valide, usando nuestro
 * convenio como servicio de verificacion gratuito (riesgos R-01 y R-05). El
 * limite es lo que separa «reintentar tras un rechazo» de «iterar hasta
 * acertar».
 *
 * La ventana del limite es MAS LARGA que la de expiracion a proposito: la
 * primera acota los intentos contra un tercero y debe medirse en horas; la
 * segunda solo libera un formulario abandonado y debe ser corta para no dejar
 * fuera a quien de verdad quiere solicitar.
 */
final readonly class ReapplicationPolicy
{
    public function __construct(
        /** Vigencia de una solicitud en curso sin actividad, en minutos. */
        private int $inProgressWindowMinutes,
        /** Ventana en la que se cuentan los rechazos de identidad, en horas. */
        private int $rejectedWindowHours,
        /** Rechazos permitidos dentro de esa ventana. */
        private int $maxRejectedAttempts,
    ) {}

    public function decide(?ApplicationSnapshot $existing, DateTimeImmutable $now): ReapplicationDecision
    {
        if ($existing === null) {
            return ReapplicationDecision::allow();
        }

        // Se comprueba ANTES que todo lo demas: ser cliente no caduca, y
        // hacerle esperar por una ventana de expiracion seria decirle que
        // vuelva a un sitio donde nunca va a poder entrar.
        if ($existing->belongsToCustomer) {
            return ReapplicationDecision::blockAlreadyCustomer();
        }

        $rejectedInWindow = $this->rejectedWithinWindow($existing, $now);

        if ($rejectedInWindow >= $this->maxRejectedAttempts) {
            return ReapplicationDecision::blockRetryLimit(
                $this->minutesUntilOldestRejectionLeavesWindow($existing, $now)
            );
        }

        if (! $existing->isInProgress()) {
            // Abandonada o cerrada: no estorba.
            return ReapplicationDecision::allow();
        }

        $minutesSinceActivity = $this->minutesBetween($existing->lastActivityAt, $now);

        if ($minutesSinceActivity >= $this->inProgressWindowMinutes) {
            return ReapplicationDecision::abandonPreviousThenAllow();
        }

        return ReapplicationDecision::blockInProgress(
            max(1, $this->inProgressWindowMinutes - $minutesSinceActivity)
        );
    }

    private function rejectedWithinWindow(ApplicationSnapshot $existing, DateTimeImmutable $now): int
    {
        $threshold = $now->modify(sprintf('-%d hours', $this->rejectedWindowHours));

        $inWindow = array_filter(
            $existing->rejectedValidationsAt,
            static fn (DateTimeImmutable $at): bool => $at > $threshold
        );

        return count($inWindow);
    }

    /**
     * Cuanto falta para que el rechazo mas antiguo de la ventana salga de ella
     * y quede un intento libre. Es un dato real y no una promesa redonda.
     */
    private function minutesUntilOldestRejectionLeavesWindow(
        ApplicationSnapshot $existing,
        DateTimeImmutable $now,
    ): int {
        $threshold = $now->modify(sprintf('-%d hours', $this->rejectedWindowHours));

        $inWindow = array_values(array_filter(
            $existing->rejectedValidationsAt,
            static fn (DateTimeImmutable $at): bool => $at > $threshold
        ));

        if ($inWindow === []) {
            return 0;
        }

        usort($inWindow, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        $expiresAt = $inWindow[0]->modify(sprintf('+%d hours', $this->rejectedWindowHours));

        return max(1, $this->minutesBetween($now, $expiresAt));
    }

    private function minutesBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}
