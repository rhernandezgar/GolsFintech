<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\ExtractedIdentityData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La proyeccion de lo extraido por OCR tiene que sostener dos propiedades a la
 * vez, y son las dos que estas pruebas fijan:
 *
 *   1. El prospecto RECONOCE su dato. Un `[REDACTED]` cumpliria la regla de
 *      seguridad y dejaria la pantalla de revision sin nada que revisar, que es
 *      justo lo que el diseno pide evitar.
 *   2. La respuesta NO transporta el dato completo. Ni la CURP ni el RFC salen
 *      enteros, aunque el destinatario sea el propio titular (RS-03).
 *
 * Sin base de datos y sin Laravel: es dominio.
 */
final class ExtractedIdentityDataTest extends TestCase
{
    /** @return array<string, mixed> */
    private function ocrResult(array $fields, float|string|null $confidence = 0.97): array
    {
        $result = ['provider' => 'fake', 'fields' => $fields];

        if ($confidence !== null) {
            $result['confidence'] = $confidence;
        }

        return $result;
    }

    #[Test]
    public function the_curp_never_travels_complete(): void
    {
        $curp = 'PELA920323MDFRPNL4';

        $extracted = ExtractedIdentityData::fromOcrResult(
            $this->ocrResult(['curp' => $curp])
        );

        $projected = $extracted->fields['curp']['value'];

        $this->assertNotSame($curp, $projected);
        $this->assertStringNotContainsString($curp, json_encode($extracted->toArray()));
        // Reconocible: los cuatro primeros y los dos ultimos, como Curp::masked().
        $this->assertSame('PELA************L4', $projected);
        $this->assertTrue($extracted->fields['curp']['masked']);
    }

    #[Test]
    public function the_rfc_never_travels_complete(): void
    {
        $extracted = ExtractedIdentityData::fromOcrResult(
            $this->ocrResult(['rfc' => 'PELA920323AB1'])
        );

        $this->assertSame('PELA*******B1', $extracted->fields['rfc']['value']);
        $this->assertTrue($extracted->fields['rfc']['masked']);
    }

    #[Test]
    public function a_sensitive_key_is_recognised_by_substring(): void
    {
        // El proveedor puede nombrar el campo como le parezca. Cubrir solo la
        // clave exacta `curp` dejaria pasar `curp_detectada` en claro.
        $extracted = ExtractedIdentityData::fromOcrResult(
            $this->ocrResult(['curp_detectada' => 'PELA920323MDFRPNL4'])
        );

        $this->assertTrue($extracted->fields['curp_detectada']['masked']);
        $this->assertSame('PELA************L4', $extracted->fields['curp_detectada']['value']);
    }

    #[Test]
    public function the_document_number_keeps_only_its_tail(): void
    {
        $extracted = ExtractedIdentityData::fromOcrResult(
            $this->ocrResult(['document_number' => 'A1B2C3D4E5F6'])
        );

        $this->assertSame('********E5F6', $extracted->fields['document_number']['value']);
        $this->assertTrue($extracted->fields['document_number']['masked']);
    }

    #[Test]
    public function a_value_too_short_to_split_is_hidden_whole(): void
    {
        // Enmascarar "ABC123" conservando cuatro y dos lo dejaria intacto. Mas
        // vale no mostrarlo que mostrarlo completo creyendo que se enmascaro.
        $extracted = ExtractedIdentityData::fromOcrResult(
            $this->ocrResult(['curp' => 'ABC123'])
        );

        $this->assertSame('******', $extracted->fields['curp']['value']);
    }

    #[Test]
    public function the_data_the_prospect_must_confirm_travels_readable(): void
    {
        // Un nombre o una fecha de nacimiento enmascarados harian imposible
        // detectar que el OCR ley mal, que es el proposito de la pantalla.
        $extracted = ExtractedIdentityData::fromOcrResult(
            $this->ocrResult(['full_name' => 'ANA PEREZ LOPEZ', 'birth_date' => '1990-01-01'])
        );

        $this->assertSame('ANA PEREZ LOPEZ', $extracted->fields['full_name']['value']);
        $this->assertFalse($extracted->fields['full_name']['masked']);
        $this->assertSame('1990-01-01', $extracted->fields['birth_date']['value']);
        $this->assertFalse($extracted->fields['birth_date']['masked']);
    }

    #[Test]
    public function the_confidence_becomes_three_levels_of_legibility(): void
    {
        $high = ExtractedIdentityData::fromOcrResult($this->ocrResult(['full_name' => 'X'], 0.97));
        $medium = ExtractedIdentityData::fromOcrResult($this->ocrResult(['full_name' => 'X'], 0.80));
        $low = ExtractedIdentityData::fromOcrResult($this->ocrResult(['full_name' => 'X'], 0.40));

        $this->assertSame('high', $high->legibility);
        $this->assertSame('medium', $medium->legibility);
        $this->assertSame('low', $low->legibility);

        // El umbral vive en el dominio, no en la vista (RS-04).
        $this->assertFalse($high->needsCarefulReview());
        $this->assertTrue($medium->needsCarefulReview());
        $this->assertTrue($low->needsCarefulReview());
    }

    #[Test]
    public function a_missing_confidence_is_unknown_and_asks_for_review(): void
    {
        // Sin confianza declarada no se asume que se ley bien: se pide revisar.
        $extracted = ExtractedIdentityData::fromOcrResult(
            $this->ocrResult(['full_name' => 'ANA PEREZ LOPEZ'], null)
        );

        $this->assertNull($extracted->confidence);
        $this->assertSame('unknown', $extracted->legibility);
        $this->assertTrue($extracted->needsCarefulReview());
    }

    #[Test]
    public function nothing_to_show_produces_null(): void
    {
        // Un bloque vacio en la respuesta solo confundiria a la interfaz.
        $this->assertNull(ExtractedIdentityData::fromOcrResult(null));
        $this->assertNull(ExtractedIdentityData::fromOcrResult([]));
        $this->assertNull(ExtractedIdentityData::fromOcrResult(['fields' => []]));
        $this->assertNull(ExtractedIdentityData::fromOcrResult(['confidence' => 0.9]));
    }

    #[Test]
    public function a_nested_structure_from_the_provider_is_not_projected_blindly(): void
    {
        // No se sabe que lleva dentro. Volcarla seria la via por la que un dato
        // sensible se escapa sin que nadie lo haya decidido.
        $extracted = ExtractedIdentityData::fromOcrResult($this->ocrResult([
            'full_name' => 'ANA PEREZ LOPEZ',
            'raw_provider_payload' => ['curp' => 'PELA920323MDFRPNL4'],
        ]));

        $this->assertArrayNotHasKey('raw_provider_payload', $extracted->fields);
        $this->assertStringNotContainsString(
            'PELA920323MDFRPNL4',
            json_encode($extracted->toArray())
        );
    }
}
