<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Domain\Exception\DocumentUploadRejectedException;
use App\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * La capa HTTP no lee el mensaje tecnico de una excepcion (VUL-05, regla de
 * seguridad 8).
 *
 * ### Por que la regla es «no leerlo», y no «no devolverlo»
 *
 * La regla que de verdad importa es que el detalle tecnico no llegue al cliente.
 * Comprobar exactamente eso exigiria seguir el valor desde el `catch` hasta la
 * respuesta, y un analisis asi es fragil: se le escapa una variable intermedia,
 * una interpolacion o un `sprintf`, y basta con que se le escape una vez para
 * que la prueba deje de servir mientras sigue en verde. Eso es lo peor que le
 * puede pasar a un control —VUL-14 fue justo ese fallo—.
 *
 * La regla que si se puede comprobar sin ambiguedad es mas estricta: **en
 * `app/Http` no se llama al mensaje tecnico, punto**. No hace falta, y esa es la
 * clave de que no sea una restriccion arbitraria:
 *
 *   - Para el cliente existe `userMessage()`, que toda `DomainException`
 *     implementa y que nace generico.
 *   - Para el registro se pasa la EXCEPCION ENTERA (`'exception' => $e`), que
 *     ademas es mejor: el registro conserva clase, traza y excepcion previa, no
 *     solo una linea de texto.
 *
 * Con las dos vias cubiertas, cualquier lectura del mensaje tecnico en la capa
 * HTTP es o un descuido o una fuga. Una regla mas estricta y decidible vale mas
 * que una exacta e incomprobable.
 *
 * Es la misma comprobacion que hace `scripts/verificar_avance.sh` en su seccion
 * T10, pero dentro de la bateria: si alguien la incumple, falla la integracion
 * continua y no solo una auditoria que se corre a mano.
 */
final class ControllerErrorExposureTest extends TestCase
{
    /**
     * Lecturas del detalle tecnico de una excepcion. `getTraceAsString()` es
     * todavia mas grave que `getMessage()`: expone rutas absolutas del servidor
     * y la cadena de llamadas.
     */
    private const FORBIDDEN_IN_HTTP = [
        'getMessage()',
        'getTraceAsString()',
        'getTrace()',
        'getFile()',
        'getLine()',
    ];

    /** @return list<SplFileInfo> */
    private function phpFilesIn(string $relativePath): array
    {
        $directory = dirname(__DIR__, 3).'/'.$relativePath;
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    #[Test]
    public function the_http_layer_has_code(): void
    {
        // Un directorio vacio tampoco expone nada, y eso no acreditaria nada.
        $this->assertGreaterThan(15, count($this->phpFilesIn('app/Http')));
    }

    #[Test]
    public function no_controller_reads_the_technical_message_of_an_exception(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn('app/Http') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (explode("\n", $contents) as $number => $line) {
                foreach (self::FORBIDDEN_IN_HTTP as $forbidden) {
                    if (str_contains($line, $forbidden)) {
                        $offenders[] = sprintf(
                            '%s:%d usa %s',
                            str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname()),
                            $number + 1,
                            $forbidden,
                        );
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['La capa HTTP no lee el mensaje tecnico de una excepcion (VUL-05, regla 8).'],
            ['Para el cliente: $e->userMessage(). Para el registro: ["exception" => $e].'],
            $offenders,
        )));
    }

    /**
     * La alternativa tiene que existir de verdad, o la regla de arriba es una
     * prohibicion sin salida y alguien acabara rodeandola.
     */
    #[Test]
    public function every_domain_exception_offers_a_generic_message_to_the_client(): void
    {
        $withoutUserMessage = [];

        foreach ($this->phpFilesIn('app/Domain/Exception') as $file) {
            $contents = (string) file_get_contents($file->getPathname());
            $name = $file->getBasename('.php');

            // La clase base declara los metodos como abstractos; las concretas
            // los implementan.
            if ($name === 'DomainException') {
                continue;
            }

            if (! str_contains($contents, 'function userMessage()')
                || ! str_contains($contents, 'function errorCode()')) {
                $withoutUserMessage[] = $name;
            }
        }

        $this->assertSame(
            [],
            $withoutUserMessage,
            'Estas excepciones de dominio no ofrecen userMessage()/errorCode(), '
            .'asi que la capa HTTP no tendria de donde sacar el mensaje al usuario: '
            .implode(', ', $withoutUserMessage),
        );
    }

    /**
     * El rechazo de una carga fue el ultimo caso que devolvia `getMessage()` al
     * cliente, y lo devolvia porque la excepcion tenia UN solo mensaje: el
     * tecnico y el del usuario eran el mismo. Mientras eso siga siendo asi para
     * alguna excepcion, la regla depende de que cada `catch` se acuerde.
     */
    #[Test]
    public function the_upload_rejection_lives_in_the_domain_hierarchy(): void
    {
        $this->assertTrue(
            class_exists(DocumentUploadRejectedException::class),
            'DocumentUploadRejectedException tiene que existir en Domain\Exception.',
        );

        $this->assertInstanceOf(
            DomainException::class,
            DocumentUploadRejectedException::tooLarge(6_000_000, 5_242_880),
        );

        $rejection = DocumentUploadRejectedException::disallowedType('application/x-executable');

        // El detalle tecnico nombra el tipo detectado; el mensaje al usuario no.
        $this->assertStringContainsString('application/x-executable', $rejection->getMessage());
        $this->assertStringNotContainsString('application/x-executable', $rejection->userMessage());
        $this->assertSame('DOCUMENT_TYPE_NOT_ALLOWED', $rejection->errorCode());
    }
}
