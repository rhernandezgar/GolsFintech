<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Audit\AuditChain;
use App\Domain\Audit\AuditChainLink;
use App\Domain\Audit\AuditChainVerifier;
use App\Domain\Audit\ChainBreakKind;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Las tres formas de manipular una bitacora, comprobadas por separado.
 *
 * No basta con una. Un encadenamiento que solo recalcule el hash de cada registro
 * detecta la ALTERACION y se le escapan el BORRADO y la INSERCION, porque en esos
 * dos casos cada fila superviviente sigue siendo coherente consigo misma; lo que
 * cambia es la costura entre registros. Y la insercion hay que probarla en su
 * version dificil: con el atacante recalculando bien los hashes del registro que
 * mete, no con basura que cualquier comprobacion tumbaria.
 */
final class AuditChainVerifierTest extends TestCase
{
    private AuditChain $chain;

    private AuditChainVerifier $verifier;

    protected function setUp(): void
    {
        $this->chain = new AuditChain;
        $this->verifier = new AuditChainVerifier($this->chain);
    }

    /**
     * Construye un eslabon coherente: calcula su current_hash a partir del
     * previous_hash que se le pase, igual que hace el adaptador al escribir.
     *
     * @param  array<array-key, mixed>  $overrides
     */
    private function link(int $id, ?string $previousHash, array $overrides = []): AuditChainLink
    {
        $fields = array_merge([
            'prospectId' => 7,
            'affectedEntity' => 'Prospect',
            'affectedEntityId' => 7,
            'eventType' => 'prospect.started',
            'actor' => 'prospect:7',
            'ipAddress' => '203.0.113.10',
            'eventAt' => new DateTimeImmutable('2026-08-21 10:0'.$id.':00'),
            'metadata' => ['step' => 'p'.$id],
        ], $overrides);

        return new AuditChainLink(
            id: $id,
            prospectId: $fields['prospectId'],
            affectedEntity: $fields['affectedEntity'],
            affectedEntityId: $fields['affectedEntityId'],
            eventType: $fields['eventType'],
            actor: $fields['actor'],
            ipAddress: $fields['ipAddress'],
            eventAt: $fields['eventAt'],
            metadata: $fields['metadata'],
            previousHash: $previousHash,
            currentHash: $this->chain->hashOfFields(
                previousHash: $previousHash,
                prospectId: $fields['prospectId'],
                affectedEntity: $fields['affectedEntity'],
                affectedEntityId: $fields['affectedEntityId'],
                eventType: $fields['eventType'],
                actor: $fields['actor'],
                ipAddress: $fields['ipAddress'],
                eventAt: $fields['eventAt'],
                metadata: $fields['metadata'],
            ),
        );
    }

    /** @return list<AuditChainLink> tres eslabones bien encadenados */
    private function intactChain(): array
    {
        $first = $this->link(1, AuditChain::GENESIS);
        $second = $this->link(2, $first->currentHash);
        $third = $this->link(3, $second->currentHash);

        return [$first, $second, $third];
    }

    /** Devuelve el eslabon con los campos cambiados pero el current_hash intacto. */
    private function tamperedContent(AuditChainLink $link, string $newActor): AuditChainLink
    {
        return new AuditChainLink(
            id: $link->id,
            prospectId: $link->prospectId,
            affectedEntity: $link->affectedEntity,
            affectedEntityId: $link->affectedEntityId,
            eventType: $link->eventType,
            actor: $newActor,
            ipAddress: $link->ipAddress,
            eventAt: $link->eventAt,
            metadata: $link->metadata,
            previousHash: $link->previousHash,
            currentHash: $link->currentHash,
        );
    }

    public function test_an_intact_chain_is_reported_as_intact(): void
    {
        $result = $this->verifier->verify($this->intactChain());

        $this->assertTrue($result->isIntact());
        $this->assertSame(3, $result->verifiedRecords);
        $this->assertSame(3, $result->tipRecordId);
        $this->assertNotNull($result->tipHash);
    }

    public function test_an_empty_chain_is_intact_and_has_no_tip(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertTrue($result->isIntact());
        $this->assertSame(0, $result->verifiedRecords);
        $this->assertNull($result->tipHash);
    }

    // ---------------------------------------------------------------- (1) alteracion

    public function test_altering_the_content_of_a_record_is_detected(): void
    {
        [$first, $second, $third] = $this->intactChain();

        $result = $this->verifier->verify([$first, $this->tamperedContent($second, 'intruder'), $third]);

        $this->assertFalse($result->isIntact());
        $this->assertCount(1, $result->breaks);
        $this->assertSame(ChainBreakKind::ContentAltered, $result->breaks[0]->kind);
        $this->assertSame(2, $result->breaks[0]->recordId);
        // El registro alterado, no un generico "la cadena esta rota".
        $this->assertSame('intruder', $result->breaks[0]->actor);
    }

    public function test_altering_only_the_metadata_is_detected(): void
    {
        $first = $this->link(1, AuditChain::GENESIS);
        $forged = new AuditChainLink(
            id: 1, prospectId: 7, affectedEntity: 'Prospect', affectedEntityId: 7,
            eventType: 'prospect.started', actor: 'prospect:7', ipAddress: '203.0.113.10',
            eventAt: $first->eventAt, metadata: ['step' => 'otro valor'],
            previousHash: null, currentHash: $first->currentHash,
        );

        $this->assertFalse($this->verifier->verify([$forged])->isIntact());
    }

    public function test_altering_only_the_ip_address_is_detected(): void
    {
        $first = $this->link(1, AuditChain::GENESIS);
        $forged = new AuditChainLink(
            id: 1, prospectId: 7, affectedEntity: 'Prospect', affectedEntityId: 7,
            eventType: 'prospect.started', actor: 'prospect:7', ipAddress: '198.51.100.99',
            eventAt: $first->eventAt, metadata: $first->metadata,
            previousHash: null, currentHash: $first->currentHash,
        );

        $this->assertFalse($this->verifier->verify([$forged])->isIntact());
    }

    // ------------------------------------------------------------------- (2) borrado

    public function test_deleting_an_intermediate_record_is_detected(): void
    {
        [$first, $second, $third] = $this->intactChain();

        // Desaparece el segundo. El primero y el tercero siguen siendo coherentes
        // consigo mismos: solo la costura entre ellos delata el borrado.
        $result = $this->verifier->verify([$first, $third]);

        $this->assertFalse($result->isIntact());
        $this->assertCount(1, $result->breaks);
        $this->assertSame(ChainBreakKind::LinkMismatch, $result->breaks[0]->kind);
        $this->assertSame(3, $result->breaks[0]->recordId);
        $this->assertSame(1, $result->breaks[0]->previousRecordId);
        $this->assertSame($first->currentHash, $result->breaks[0]->expected);
        $this->assertSame($second->currentHash, $result->breaks[0]->stored);
    }

    public function test_deleting_the_first_record_is_detected(): void
    {
        [, $second, $third] = $this->intactChain();

        $result = $this->verifier->verify([$second, $third]);

        $this->assertFalse($result->isIntact());
        $this->assertSame(ChainBreakKind::LinkMismatch, $result->breaks[0]->kind);
        $this->assertSame(2, $result->breaks[0]->recordId);
        // Ahora el primero de la tabla deberia abrir la cadena, y trae previous_hash.
        $this->assertNull($result->breaks[0]->expected);
        $this->assertNotNull($result->breaks[0]->stored);
    }

    // ----------------------------------------------------------------- (3) insercion

    public function test_inserting_a_forged_record_between_two_existing_ones_is_detected(): void
    {
        [$first, $second, $third] = $this->intactChain();

        // La version dificil: el atacante calcula bien el hash del registro que
        // inserta, asi que el registro nuevo pasa las dos comprobaciones. Lo que no
        // puede es arreglar al que va detras sin rehacer todo el tramo final.
        $forged = $this->link(4, $first->currentHash, ['actor' => 'intruder', 'eventType' => 'prospect.approved']);

        $result = $this->verifier->verify([$first, $forged, $second, $third]);

        $this->assertFalse($result->isIntact());
        $this->assertCount(1, $result->breaks);
        $this->assertSame(ChainBreakKind::LinkMismatch, $result->breaks[0]->kind);
        // El registro senalado es el sucesor, que es inocente: es el primero que ya
        // no encaja. La ruptura esta entre el #4 insertado y el #2.
        $this->assertSame(2, $result->breaks[0]->recordId);
        $this->assertSame(4, $result->breaks[0]->previousRecordId);
        $this->assertSame($forged->currentHash, $result->breaks[0]->expected);
        $this->assertSame($first->currentHash, $result->breaks[0]->stored);
    }

    public function test_inserting_a_record_with_an_invented_link_is_detected_on_the_record_itself(): void
    {
        [$first, $second, $third] = $this->intactChain();

        $forged = $this->link(4, str_repeat('a', 64), ['actor' => 'intruder']);

        $result = $this->verifier->verify([$first, $forged, $second, $third]);

        $this->assertFalse($result->isIntact());
        $this->assertSame(4, $result->firstBreak()->recordId);
        $this->assertSame(ChainBreakKind::LinkMismatch, $result->firstBreak()->kind);
    }

    public function test_appending_a_record_at_the_end_without_the_right_link_is_detected(): void
    {
        [$first, $second, $third] = $this->intactChain();

        $forged = $this->link(9, $second->currentHash, ['actor' => 'intruder']);

        $result = $this->verifier->verify([$first, $second, $third, $forged]);

        $this->assertFalse($result->isIntact());
        $this->assertSame(9, $result->firstBreak()->recordId);
    }

    public function test_the_content_check_does_not_mask_the_link_check(): void
    {
        [$first, , $third] = $this->intactChain();

        // Un borrado intermedio se reporta como UN eslabon roto, no ademas como un
        // contenido alterado que no lo esta: el diagnostico tiene que ser exacto
        // para que sirva de algo en un incidente.
        $result = $this->verifier->verify([$first, $third]);

        $kinds = array_map(static fn ($break): string => $break->kind->value, $result->breaks);

        $this->assertSame(['link_mismatch'], $kinds);
    }
}
