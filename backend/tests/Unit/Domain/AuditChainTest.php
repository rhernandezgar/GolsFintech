<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Audit\AuditChain;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * El encadenamiento es lo que convierte la bitacora en evidencia: si un registro se
 * altera, el hash deja de coincidir y todos los posteriores quedan invalidados.
 */
final class AuditChainTest extends TestCase
{
    private AuditChain $chain;

    protected function setUp(): void
    {
        $this->chain = new AuditChain();
    }

    private function event(string $entity = 'Prospect', array $metadata = ['step' => 'p1']): AuditEvent
    {
        return new AuditEvent(
            eventType: AuditEventType::ProspectStarted,
            affectedEntity: $entity,
            affectedEntityId: 7,
            prospectId: 7,
            context: new AuditContext('prospect:7', '203.0.113.10'),
            eventAt: new DateTimeImmutable('2026-08-21 10:00:00'),
            metadata: $metadata,
        );
    }

    public function test_the_hash_is_sha256_and_never_md5_or_sha1(): void
    {
        $hash = $this->chain->hash($this->event(), AuditChain::GENESIS);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame(32, strlen($hash), 'Un hash de 32 caracteres seria MD5.');
        $this->assertNotSame(40, strlen($hash), 'Un hash de 40 caracteres seria SHA-1.');
    }

    public function test_the_same_event_always_produces_the_same_hash(): void
    {
        $this->assertSame(
            $this->chain->hash($this->event(), 'abc'),
            $this->chain->hash($this->event(), 'abc')
        );
    }

    public function test_the_metadata_key_order_does_not_change_the_hash(): void
    {
        // La serializacion es canonica: el mismo contenido, escrito en otro orden,
        // debe dar el mismo hash o la verificacion no seria reproducible.
        $first = $this->chain->hash($this->event('Prospect', ['a' => 1, 'b' => 2]), null);
        $second = $this->chain->hash($this->event('Prospect', ['b' => 2, 'a' => 1]), null);

        $this->assertSame($first, $second);
    }

    public function test_changing_the_content_breaks_the_hash(): void
    {
        $original = $this->chain->hash($this->event(), null);

        $this->assertNotSame($original, $this->chain->hash($this->event('Customer'), null));
        $this->assertNotSame($original, $this->chain->hash($this->event('Prospect', ['step' => 'p2']), null));
    }

    public function test_changing_the_previous_link_breaks_the_chain(): void
    {
        $event = $this->event();
        $first = $this->chain->hash($event, null);
        $tampered = $this->chain->hash($event, str_repeat('0', 64));

        $this->assertNotSame($first, $tampered);
        $this->assertTrue($this->chain->verify($event, null, $first));
        $this->assertFalse($this->chain->verify($event, str_repeat('0', 64), $first));
    }

    public function test_a_three_link_chain_verifies_end_to_end(): void
    {
        $events = [$this->event('Prospect'), $this->event('IdentityDocument'), $this->event('CreditApplication')];
        $previous = AuditChain::GENESIS;
        $links = [];

        foreach ($events as $event) {
            $hash = $this->chain->hash($event, $previous);
            $links[] = [$event, $previous, $hash];
            $previous = $hash;
        }

        foreach ($links as [$event, $previousHash, $hash]) {
            $this->assertTrue($this->chain->verify($event, $previousHash, $hash));
        }
    }
}
