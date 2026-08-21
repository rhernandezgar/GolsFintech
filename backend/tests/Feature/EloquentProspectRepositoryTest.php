<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Curp;
use App\Domain\Identity\Rfc;
use App\Domain\Port\ProspectRepository;
use App\Domain\Prospect\CaptureMethod;
use App\Domain\Prospect\CaptureStatus;
use App\Domain\Prospect\Prospect;
use App\Domain\Prospect\Sex;
use App\Domain\Shared\Money;
use App\Infrastructure\Security\PiiHasher;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adaptador de persistencia: se comprueba la ida y vuelta entidad -> fila -> entidad
 * y, sobre todo, que la CURP queda cifrada en la columna y que su busqueda va por
 * hash y no descifrando.
 */
final class EloquentProspectRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private const CURP = 'HEGG560427MVZRRL04';

    private function savedProspect(): Prospect
    {
        $prospect = Prospect::start(CaptureMethod::Manual, new DateTimeImmutable('2026-08-21 09:00:00'));
        $prospect->captureData(
            fullName: 'Ana Perez Lopez',
            curp: Curp::fromString(self::CURP),
            rfc: Rfc::fromString('GODE561231GR8'),
            age: 34,
            sex: Sex::Female,
            monthlyIncome: Money::fromDecimalString('18000.00'),
            businessType: 'abarrotes',
        );

        return $this->repository()->save($prospect);
    }

    private function repository(): ProspectRepository
    {
        return $this->app->make(ProspectRepository::class);
    }

    public function test_it_saves_and_reconstitutes_the_entity(): void
    {
        $saved = $this->savedProspect();

        $this->assertNotNull($saved->id());

        $loaded = $this->repository()->findByPublicId($saved->publicId());

        $this->assertNotNull($loaded);
        $this->assertSame('Ana Perez Lopez', $loaded->fullName());
        $this->assertSame(self::CURP, (string) $loaded->curp());
        $this->assertSame('18000.00', $loaded->monthlyIncome()?->toDecimalString());
        $this->assertSame(1800000, $loaded->monthlyIncome()?->cents);
        $this->assertSame(CaptureStatus::DataCaptured, $loaded->captureStatus());
        $this->assertSame(CaptureMethod::Manual, $loaded->captureMethod());
    }

    public function test_the_curp_is_encrypted_at_rest(): void
    {
        $this->savedProspect();

        $raw = DB::table('prospects')->select('curp', 'curp_hash')->first();

        $this->assertNotNull($raw);
        $this->assertNotSame(self::CURP, $raw->curp);
        $this->assertStringNotContainsString(self::CURP, (string) $raw->curp);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $raw->curp_hash);
    }

    public function test_the_lookup_hash_is_deterministic_and_keyed(): void
    {
        $this->savedProspect();

        $raw = DB::table('prospects')->select('curp_hash')->first();
        $hasher = $this->app->make(PiiHasher::class);

        $this->assertSame($hasher->hash(self::CURP), $raw?->curp_hash);
        // HMAC, no SHA-256 a secas: sin la llave no se puede reproducir el hash.
        $this->assertNotSame(hash('sha256', self::CURP), $raw?->curp_hash);
    }

    public function test_it_finds_a_prospect_by_curp_without_decrypting(): void
    {
        $saved = $this->savedProspect();

        $found = $this->repository()->findByCurp(Curp::fromString(self::CURP));

        $this->assertNotNull($found);
        $this->assertTrue($found->publicId()->equals($saved->publicId()));
        $this->assertTrue($this->repository()->existsWithCurp(Curp::fromString(self::CURP)));
    }

    public function test_saving_twice_updates_the_same_row(): void
    {
        $prospect = $this->savedProspect();
        $prospect->confirmData();
        $this->repository()->save($prospect);

        $this->assertSame(1, DB::table('prospects')->count());
        $this->assertSame(
            CaptureStatus::DataConfirmed,
            $this->repository()->findByPublicId($prospect->publicId())?->captureStatus()
        );
    }
}
