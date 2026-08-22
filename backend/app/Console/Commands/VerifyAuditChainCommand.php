<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\ChainBreak;
use App\Domain\Audit\ChainBreakKind;
use App\Domain\Audit\ChainVerificationResult;
use App\Infrastructure\Persistence\Eloquent\AuditChainInspector;
use Illuminate\Console\Command;

/**
 * Verifica el encadenamiento SHA-256 de la bitacora (RS-06, RNF-07).
 *
 * Pensado para correr en integracion continua: **termina con codigo distinto de
 * cero si la cadena esta rota**, de modo que la tuberia se detiene sola sin que
 * nadie tenga que leer la salida.
 *
 *   0  cadena integra
 *   1  cadena rota: hay al menos una ruptura
 *   2  la punta no coincide con el --expect-tip recibido
 *
 * El comando NO escribe en la bitacora. Registrar cada verificacion la haria crecer
 * con cada corrida de CI y, peor, anadiria un eslabon en mitad de la comprobacion.
 * Auditar la bitacora es una lectura.
 *
 * Sobre --expect-tip: la comprobacion interna no puede detectar que alguien borre
 * los ULTIMOS registros, porque lo que queda sigue siendo una cadena coherente. La
 * unica defensa es un ancla externa: se guarda el hash de la punta fuera de esta
 * base de datos y se le pasa aqui. Si la bitacora se truncó, no coincide.
 */
final class VerifyAuditChainCommand extends Command
{
    private const EXIT_BROKEN_CHAIN = 1;

    private const EXIT_TIP_MISMATCH = 2;

    /** Tope de rupturas que se listan: mas alla, el detalle deja de ayudar. */
    private const MAX_REPORTED_BREAKS = 20;

    protected $signature = 'audit:verify-chain
                            {--expect-tip= : Hash que debe tener el ultimo registro (ancla externa contra el truncado)}
                            {--chunk=500 : Registros por pagina al recorrer la bitacora}
                            {--json : Salida en JSON para consumo automatico}';

    protected $description = 'Verifica el encadenamiento SHA-256 de la bitacora y termina en codigo distinto de cero si esta rota';

    public function handle(AuditChainInspector $inspector): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $result = $inspector->verifyStoredChain($chunk);

        $expectedTip = $this->option('expect-tip');
        $tipMatches = $expectedTip === null || hash_equals((string) $expectedTip, (string) $result->tipHash);

        if ($this->option('json')) {
            $this->line($this->asJson($result, $expectedTip === null ? null : $tipMatches));
        } else {
            $this->render($result, $expectedTip === null ? null : $tipMatches, (string) $expectedTip);
        }

        if (! $result->isIntact()) {
            return self::EXIT_BROKEN_CHAIN;
        }

        return $tipMatches ? self::SUCCESS : self::EXIT_TIP_MISMATCH;
    }

    private function render(ChainVerificationResult $result, ?bool $tipMatches, string $expectedTip): void
    {
        $this->newLine();

        if ($result->verifiedRecords === 0) {
            $this->warn('La bitacora esta vacia: no hay cadena que verificar.');
        } elseif ($result->isIntact()) {
            $this->info(sprintf('Cadena integra: %d registro(s) verificado(s).', $result->verifiedRecords));
        } else {
            $first = $result->firstBreak();

            $this->error(sprintf(
                'CADENA ROTA: %d inconsistencia(s) en %d registro(s) verificado(s).',
                count($result->breaks),
                $result->verifiedRecords,
            ));
            $this->line(sprintf(
                '  Primera ruptura en el registro #%d (%s, %s).',
                $first->recordId,
                $first->eventType,
                $first->eventAt,
            ));
            $this->newLine();

            foreach (array_slice($result->breaks, 0, self::MAX_REPORTED_BREAKS) as $break) {
                $this->renderBreak($break);
            }

            $hidden = count($result->breaks) - self::MAX_REPORTED_BREAKS;
            if ($hidden > 0) {
                $this->line(sprintf('  ... y %d ruptura(s) mas.', $hidden));
            }
        }

        if ($result->tipHash !== null) {
            $this->newLine();
            $this->line(sprintf('  Punta de la cadena: registro #%d', $result->tipRecordId));
            $this->line(sprintf('  Hash de la punta:   %s', $result->tipHash));
            $this->line('  Guarda ese hash fuera de esta base y pasalo como --expect-tip para detectar un truncado.');
        }

        if ($tipMatches === false) {
            $this->newLine();
            $this->error('LA PUNTA NO COINCIDE con el ancla externa: la bitacora pudo perder registros del final.');
            $this->line(sprintf('  Esperado:  %s', $expectedTip));
            $this->line(sprintf('  Almacenado: %s', $result->tipHash ?? '(bitacora vacia)'));
        } elseif ($tipMatches === true) {
            $this->newLine();
            $this->info('La punta coincide con el ancla externa recibida.');
        }

        $this->newLine();
    }

    private function renderBreak(ChainBreak $break): void
    {
        [$title, $expectedLabel, $storedLabel] = match ($break->kind) {
            ChainBreakKind::ContentAltered => [
                'contenido alterado: el registro cambio despues de escribirse',
                'hash recalculado ',
                'hash almacenado  ',
            ],
            ChainBreakKind::LinkMismatch => [
                $break->previousRecordId === null
                    ? 'eslabon roto: el primer registro deberia abrir la cadena y no lo hace (falta el original?)'
                    : sprintf('eslabon roto: no enlaza con el registro #%d que lo precede', $break->previousRecordId),
                'previous_hash esperado',
                'previous_hash guardado',
            ],
        };

        $this->line(sprintf('  <fg=red>#%d</> %s', $break->recordId, $title));
        $this->line(sprintf('       evento: %s | actor: %s | event_at: %s', $break->eventType, $break->actor, $break->eventAt));
        $this->line(sprintf('       %s: %s', $expectedLabel, $break->expected ?? '(nulo: apertura de cadena)'));
        $this->line(sprintf('       %s: %s', $storedLabel, $break->stored ?? '(nulo: apertura de cadena)'));
        $this->newLine();
    }

    private function asJson(ChainVerificationResult $result, ?bool $tipMatches): string
    {
        return (string) json_encode([
            'intact' => $result->isIntact(),
            'verified_records' => $result->verifiedRecords,
            'tip_record_id' => $result->tipRecordId,
            'tip_hash' => $result->tipHash,
            'tip_matches_anchor' => $tipMatches,
            'breaks' => array_map(static fn (ChainBreak $break): array => $break->toArray(), $result->breaks),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
