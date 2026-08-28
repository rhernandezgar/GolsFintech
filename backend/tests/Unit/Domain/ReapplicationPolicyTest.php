<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Prospect\ApplicationSnapshot;
use App\Domain\Prospect\CaptureStatus;
use App\Domain\Prospect\ReapplicationOutcome;
use App\Domain\Prospect\ReapplicationPolicy;
use App\Domain\Shared\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «Una CURP, una solicitud ACTIVA a la vez» (VUL-17).
 *
 * El modelo anterior —«una CURP, una solicitud», impuesto por el UNIQUE de
 * `curp_hash`— convertia cualquier intento fallido en un veto permanente. Estas
 * pruebas fijan los cinco desenlaces y, sobre todo, que **el rechazo no es
 * definitivo**: es la propiedad que el diseno pedia y que la implementacion
 * anterior contradecia.
 *
 * Sin base de datos y sin Laravel: es dominio puro, y las ventanas se pasan por
 * constructor precisamente para poder recorrerlas sin viajar en el tiempo.
 */
final class ReapplicationPolicyTest extends TestCase
{
    private const WINDOW_MINUTES = 10;

    private const REJECTED_WINDOW_HOURS = 24;

    private const MAX_REJECTED = 3;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-28 12:00:00');
    }

    private function policy(): ReapplicationPolicy
    {
        return new ReapplicationPolicy(
            inProgressWindowMinutes: self::WINDOW_MINUTES,
            rejectedWindowHours: self::REJECTED_WINDOW_HOURS,
            maxRejectedAttempts: self::MAX_REJECTED,
        );
    }

    /** @param list<DateTimeImmutable> $rejected */
    private function snapshot(
        CaptureStatus $status = CaptureStatus::DataConfirmed,
        string $lastActivity = '-1 minute',
        bool $isCustomer = false,
        array $rejected = [],
    ): ApplicationSnapshot {
        return new ApplicationSnapshot(
            prospectPublicId: Uuid::fromString('11111111-1111-4111-8111-111111111111'),
            captureStatus: $status,
            lastActivityAt: $this->now->modify($lastActivity),
            belongsToCustomer: $isCustomer,
            rejectedValidationsAt: $rejected,
        );
    }

    // ============================================ 0. no hay nada ============

    #[Test]
    public function a_curp_never_seen_before_is_allowed(): void
    {
        $decision = $this->policy()->decide(null, $this->now);

        $this->assertSame(ReapplicationOutcome::Allow, $decision->outcome);
        $this->assertTrue($decision->isAllowed());
    }

    // ================================ 1. en curso, NO expirada ==============

    #[Test]
    public function a_live_application_blocks_and_says_how_many_minutes_are_left(): void
    {
        $decision = $this->policy()->decide($this->snapshot(lastActivity: '-3 minutes'), $this->now);

        $this->assertSame(ReapplicationOutcome::BlockInProgress, $decision->outcome);
        $this->assertFalse($decision->isAllowed());
        // Minutaje REAL, no un texto fijo: 10 de ventana menos 3 de inactividad.
        $this->assertSame(7, $decision->retryAfterMinutes);
    }

    /** @return array<string, array{CaptureStatus}> */
    public static function inProgressStatuses(): array
    {
        return [
            'started' => [CaptureStatus::Started],
            'data_captured' => [CaptureStatus::DataCaptured],
            'document_uploaded' => [CaptureStatus::DocumentUploaded],
            'data_confirmed' => [CaptureStatus::DataConfirmed],
        ];
    }

    #[Test]
    #[DataProvider('inProgressStatuses')]
    public function every_non_terminal_status_counts_as_a_live_application(CaptureStatus $status): void
    {
        // Si alguno dejara de contar, dos expedientes vivos competirian por la
        // misma persona, que es justo lo que la restriccion existe para evitar.
        $decision = $this->policy()->decide(
            $this->snapshot(status: $status, lastActivity: '-1 minute'),
            $this->now
        );

        $this->assertSame(ReapplicationOutcome::BlockInProgress, $decision->outcome);
    }

    #[Test]
    public function the_remaining_time_is_never_zero_while_it_still_blocks(): void
    {
        // Justo en el ultimo minuto: decir «faltan 0 minutos» y aun asi negar
        // seria contradictorio para quien lo lee.
        $decision = $this->policy()->decide($this->snapshot(lastActivity: '-9 minutes 30 seconds'), $this->now);

        $this->assertSame(ReapplicationOutcome::BlockInProgress, $decision->outcome);
        $this->assertGreaterThanOrEqual(1, $decision->retryAfterMinutes);
    }

    // ================================== 2. en curso, EXPIRADA ===============

    #[Test]
    public function an_expired_application_is_abandoned_and_the_new_one_is_allowed(): void
    {
        $decision = $this->policy()->decide($this->snapshot(lastActivity: '-11 minutes'), $this->now);

        $this->assertSame(ReapplicationOutcome::AbandonPreviousThenAllow, $decision->outcome);
        $this->assertTrue($decision->isAllowed());
    }

    #[Test]
    public function the_boundary_of_the_window_releases_the_file(): void
    {
        // Exactamente en la ventana: se libera. El limite se documenta con una
        // prueba en vez de dejarlo a la lectura del operador `>=`.
        $decision = $this->policy()->decide($this->snapshot(lastActivity: '-10 minutes'), $this->now);

        $this->assertSame(ReapplicationOutcome::AbandonPreviousThenAllow, $decision->outcome);
    }

    #[Test]
    public function an_already_abandoned_file_never_blocks(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot(status: CaptureStatus::Abandoned, lastActivity: '-1 minute'),
            $this->now
        );

        $this->assertSame(ReapplicationOutcome::Allow, $decision->outcome);
    }

    // =============================== 3. rechazada, dentro del limite ========

    #[Test]
    public function a_rejected_identity_can_apply_again(): void
    {
        // LA PROPIEDAD CENTRAL DE VUL-17. Antes, un rechazo era un veto
        // permanente; la Fase 1 contempla el rechazo por identidad no
        // verificada como un desenlace normal, no como una expulsion.
        $decision = $this->policy()->decide(
            $this->snapshot(
                status: CaptureStatus::Abandoned,
                rejected: [$this->now->modify('-2 hours')],
            ),
            $this->now
        );

        $this->assertTrue($decision->isAllowed());
    }

    #[Test]
    public function two_rejections_still_leave_one_attempt(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot(status: CaptureStatus::Abandoned, rejected: [
                $this->now->modify('-5 hours'),
                $this->now->modify('-2 hours'),
            ]),
            $this->now
        );

        $this->assertTrue($decision->isAllowed());
    }

    // =============================== 4. rechazada, fuera del limite =========

    #[Test]
    public function too_many_rejections_in_the_window_block_the_retry(): void
    {
        // Sin este limite, P4 seria un oraculo de fuerza bruta contra INE y
        // RENAPO: con la CURP como unica entrada, se pueden sondear
        // combinaciones hasta que una valide (R-01, R-05).
        $decision = $this->policy()->decide(
            $this->snapshot(status: CaptureStatus::Abandoned, rejected: [
                $this->now->modify('-5 hours'),
                $this->now->modify('-3 hours'),
                $this->now->modify('-1 hour'),
            ]),
            $this->now
        );

        $this->assertSame(ReapplicationOutcome::BlockRetryLimit, $decision->outcome);
        // Falta lo que le queda al MAS ANTIGUO para salir de la ventana: 24 h
        // menos las 5 que ya pasaron = 19 h.
        $this->assertSame(19 * 60, $decision->retryAfterMinutes);
    }

    #[Test]
    public function rejections_older_than_the_window_do_not_count(): void
    {
        // El limite es una ventana movil, no un contador acumulado de por vida:
        // si no expirara, seguiria siendo el veto permanente de antes.
        $decision = $this->policy()->decide(
            $this->snapshot(status: CaptureStatus::Abandoned, rejected: [
                $this->now->modify('-30 hours'),
                $this->now->modify('-28 hours'),
                $this->now->modify('-25 hours'),
            ]),
            $this->now
        );

        $this->assertTrue($decision->isAllowed());
    }

    #[Test]
    public function the_retry_limit_wins_over_an_expired_file(): void
    {
        // Un expediente caducado no debe reabrir la puerta a quien ya agoto sus
        // intentos: si no, bastaria esperar diez minutos para seguir sondeando.
        $decision = $this->policy()->decide(
            $this->snapshot(lastActivity: '-60 minutes', rejected: [
                $this->now->modify('-3 hours'),
                $this->now->modify('-2 hours'),
                $this->now->modify('-1 hour'),
            ]),
            $this->now
        );

        $this->assertSame(ReapplicationOutcome::BlockRetryLimit, $decision->outcome);
    }

    // ======================================= 5. ya es cliente ===============

    #[Test]
    public function a_curp_that_already_belongs_to_a_customer_is_blocked(): void
    {
        $decision = $this->policy()->decide($this->snapshot(isCustomer: true), $this->now);

        $this->assertSame(ReapplicationOutcome::BlockAlreadyCustomer, $decision->outcome);
        // Ser cliente no caduca: no se ofrece plazo, porque esperar no cambia
        // nada y decir «vuelve en 10 minutos» seria mandarlo a un sitio donde
        // nunca va a poder entrar.
        $this->assertNull($decision->retryAfterMinutes);
    }

    #[Test]
    public function being_a_customer_is_decided_before_anything_else(): void
    {
        // Aunque su expediente este caducado y aunque tenga rechazos: sigue
        // siendo cliente, y ese es el mensaje que necesita.
        $decision = $this->policy()->decide(
            $this->snapshot(
                status: CaptureStatus::Abandoned,
                lastActivity: '-5 hours',
                isCustomer: true,
                rejected: [$this->now->modify('-1 hour')],
            ),
            $this->now
        );

        $this->assertSame(ReapplicationOutcome::BlockAlreadyCustomer, $decision->outcome);
    }
}
