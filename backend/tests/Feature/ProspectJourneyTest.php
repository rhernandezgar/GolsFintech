<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\DTO\ProspectDataInput;
use App\Application\UseCase\Credit\SimulateCredit;
use App\Application\UseCase\Prospect\CaptureProspectData;
use App\Application\UseCase\Prospect\ConfirmProspectData;
use App\Application\UseCase\Prospect\StartProspectCapture;
use App\Domain\Audit\AuditContext;
use App\Domain\Exception\InvalidCurpException;
use App\Domain\Exception\InvalidStateTransitionException;
use App\Domain\Exception\UnauthorizedTermException;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\Prospect;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Recorrido de la capa de aplicacion con los adaptadores reales enchufados:
 * P1 (inicio) -> P2 (captura) -> confirmacion -> P5 (simulacion), comprobando que
 * cada paso deja su evento en la bitacora.
 */
final class ProspectJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const CURP = 'HEGG560427MVZRRL04';

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-08-21 10:00:00');
    }

    private function context(): AuditContext
    {
        return new AuditContext('prospect:anonymous', '203.0.113.10');
    }

    private function startAndCapture(string $monthlyIncome = '20000.00'): Prospect
    {
        $prospect = $this->app->make(StartProspectCapture::class)
            ->execute(CaptureMethod::Manual, '2026-08-01', $this->context(), $this->now);

        return $this->app->make(CaptureProspectData::class)->execute(
            $prospect->publicId(),
            new ProspectDataInput(
                fullName: 'Ana Perez Lopez',
                curp: self::CURP,
                rfc: 'GODE561231GR8',
                age: 34,
                sex: 'M',
                monthlyIncome: $monthlyIncome,
                businessType: null,
                email: 'ana.perez@example.mx',
                phone: '5512345678',
            ),
            $this->context(),
            $this->now
        );
    }

    public function test_the_full_journey_produces_an_offer_and_an_audit_trail(): void
    {
        $prospect = $this->startAndCapture();
        $this->app->make(ConfirmProspectData::class)->execute($prospect->publicId(), $this->context(), $this->now);

        $offer = $this->app->make(SimulateCredit::class)
            ->execute($prospect->publicId(), 12, $this->context(), $this->now);

        $this->assertSame('personal', $offer->creditType);
        $this->assertSame('6000.00', $offer->paymentCapacity);
        $this->assertSame(12, $offer->termMonths);
        $this->assertSame('MXN', $offer->currency);
        $this->assertNotSame('0.00', $offer->proposedAmount);

        $events = DB::table('audit_logs')->orderBy('id')->pluck('event_type')->all();

        $this->assertSame([
            'prospect.started',
            'prospect.data_captured',
            'prospect.data_confirmed',
            'credit_simulation.generated',
        ], $events);
    }

    public function test_the_audit_trail_is_anchored_to_the_prospect(): void
    {
        $prospect = $this->startAndCapture();

        $anchored = DB::table('audit_logs')->where('prospect_id', $prospect->id())->count();

        $this->assertSame(2, $anchored);
        $this->assertSame(
            'Prospect',
            DB::table('audit_logs')->where('prospect_id', $prospect->id())->value('affected_entity')
        );
    }

    public function test_the_audit_trail_never_stores_the_curp(): void
    {
        $this->startAndCapture();

        $metadata = (string) DB::table('audit_logs')->where('event_type', 'prospect.data_captured')->value('metadata');

        // Se registra que la CURP existe y con que metodo se capturo, nunca su valor.
        $this->assertStringNotContainsString(self::CURP, $metadata);
        $this->assertStringNotContainsString('HEGG', $metadata);
        $this->assertStringContainsString('has_curp', $metadata);
        $this->assertStringContainsString('manual', $metadata);
    }

    public function test_a_second_prospect_cannot_reuse_a_registered_curp(): void
    {
        $this->startAndCapture();

        $this->expectException(InvalidCurpException::class);

        $this->startAndCapture();
    }

    public function test_the_simulation_requires_confirmed_data(): void
    {
        $prospect = $this->startAndCapture();

        $this->expectException(InvalidStateTransitionException::class);

        $this->app->make(SimulateCredit::class)->execute($prospect->publicId(), 12, $this->context(), $this->now);
    }

    public function test_a_term_outside_the_catalogue_is_rejected_even_reaching_the_use_case(): void
    {
        // VUL-02 extremo a extremo: el plazo llega desde fuera y muere en el dominio.
        $prospect = $this->startAndCapture();
        $this->app->make(ConfirmProspectData::class)->execute($prospect->publicId(), $this->context(), $this->now);

        $this->expectException(UnauthorizedTermException::class);

        $this->app->make(SimulateCredit::class)->execute($prospect->publicId(), 9, $this->context(), $this->now);
    }
}
