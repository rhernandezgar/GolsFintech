<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Regla de dependencia hexagonal (CLAUDE.md seccion 3), comprobada de forma
 * automatica y no por revision manual: Domain no puede depender de Laravel ni de
 * ninguna capa externa, porque justo eso es lo que permite probar el motor de
 * reglas de forma aislada y determinista.
 *
 * Es la misma comprobacion que hace scripts/verificar_avance.sh, pero dentro de la
 * bateria de pruebas: si alguien contamina la capa, falla la integracion continua.
 */
final class DomainDependencyTest extends TestCase
{
    private const FORBIDDEN_IN_DOMAIN = [
        'Illuminate\\',
        'App\\Models\\',
        'App\\Http\\',
        'App\\Application\\',
        'App\\Infrastructure\\',
        'Laravel\\',
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

    public function test_the_domain_layer_has_code(): void
    {
        // Un directorio vacio tampoco importa Illuminate, y eso no acreditaria nada.
        $this->assertGreaterThan(20, count($this->phpFilesIn('app/Domain')));
    }

    public function test_the_domain_layer_does_not_depend_on_the_framework(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn('app/Domain') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN_IN_DOMAIN as $forbidden) {
                if (str_contains($contents, $forbidden)) {
                    $offenders[] = $file->getPathname().' -> '.$forbidden;
                }
            }
        }

        $this->assertSame([], $offenders, 'La capa Domain quedo contaminada: '.implode(', ', $offenders));
    }

    public function test_the_application_layer_does_not_depend_on_the_framework_or_on_adapters(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn('app/Application') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (['Illuminate\\', 'App\\Infrastructure\\', 'App\\Models\\', 'App\\Http\\'] as $forbidden) {
                if (str_contains($contents, $forbidden)) {
                    $offenders[] = $file->getPathname().' -> '.$forbidden;
                }
            }
        }

        $this->assertSame([], $offenders, 'La capa Application depende de infraestructura: '.implode(', ', $offenders));
    }

    public function test_the_seven_design_ports_exist(): void
    {
        $ports = ['ProspectRepository', 'DocumentRepository', 'OcrService', 'IdentityValidator',
            'CardIssuer', 'AuditLogger', 'NotificationSender'];

        foreach ($ports as $port) {
            $this->assertTrue(
                interface_exists('App\\Domain\\Port\\'.$port),
                sprintf('Falta el puerto %s declarado en CLAUDE.md seccion 4.', $port)
            );
        }
    }
}
