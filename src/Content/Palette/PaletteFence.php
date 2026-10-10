<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;

/**
 * Custom palette spec §4.3 and docs/internal/palette-lock-order.md. A writer normalises its ORIGINAL
 * payload against an unlocked snapshot; when the submitted or the normalised payload names a brand
 * token (or `$force`), its write runs in a transaction that takes the palette row FIRST and, when
 * the generation moved, normalises the original again against the state now held. While the row is
 * held no palette mutation can commit, so one re-normalisation is enough.
 */
final class PaletteFence
{
    /** @var list<array{rewrites: list<array<string,string>>, generation: int}> open capture() frames */
    private array $captures = [];

    public function __construct(private readonly Connection $db, private readonly PaletteState $state)
    {
    }

    /**
     * @template T
     * @param callable(PaletteSnapshot): Normalized $normalize re-run from the ORIGINAL payload on a mismatch
     * @param callable(array<string,mixed>, PaletteSnapshot): T $write the document and the snapshot it was
     *        normalised against (the held one when fenced)
     * @return T
     */
    public function write(callable $normalize, callable $write, bool $force = false): mixed
    {
        $read = $this->state->snapshot();
        $normalized = $normalize($read);
        if (!$force && !$normalized->fenced()) {
            $result = $write($normalized->doc, $read);
            $this->report($normalized, $read);
            return $result;
        }
        return $this->within(function () use ($normalize, $write, $read, $normalized): mixed {
            $held = $this->state->lock();
            if ($held->generation !== $read->generation) {
                $normalized = $normalize($held);
            }
            $result = $write($normalized->doc, $held);
            $this->report($normalized, $held);
            return $result;
        });
    }

    /**
     * Runs `$work` and reports what its writes did (custom palette plan Task 12): the rewrites every
     * write() inside it applied and the highest generation one held — what an editor's save response
     * carries. Null when nothing inside wrote through the fence. A write rolled back with its
     * transaction throws, so nothing is reported for it.
     *
     * @template T
     * @param callable(): T $work
     * @return array{0: T, 1: ?PaletteOutcome}
     */
    public function capture(callable $work): array
    {
        $this->captures[] = ['rewrites' => [], 'generation' => -1];
        try {
            $result = $work();
            $frame = $this->captures[array_key_last($this->captures)];
        } finally {
            array_pop($this->captures);
        }
        if ($this->captures !== [] && $frame['generation'] >= 0) {
            $outer = array_key_last($this->captures);
            $this->captures[$outer]['rewrites'] = [...$this->captures[$outer]['rewrites'], ...$frame['rewrites']];
            $this->captures[$outer]['generation'] = max($this->captures[$outer]['generation'], $frame['generation']);
        }
        $outcome = $frame['generation'] < 0 ? null : new PaletteOutcome($frame['rewrites'], $frame['generation']);
        return [$result, $outcome];
    }

    /**
     * A writer that normalises under the row itself (inside within()) reports what it did, so an
     * enclosing capture() sees it as it sees write()'s.
     */
    public function report(Normalized $normalized, PaletteSnapshot $snapshot): void
    {
        if ($this->captures === []) {
            return;
        }
        $top = array_key_last($this->captures);
        $this->captures[$top]['rewrites'] = [...$this->captures[$top]['rewrites'], ...$normalized->rewrites];
        $this->captures[$top]['generation'] = max($this->captures[$top]['generation'], $snapshot->generation);
    }

    /**
     * Runs `$work` in a transaction that takes the palette row first. Inside a transaction that has
     * not taken it, that would put the palette row after another lock: refused.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function within(callable $work): mixed
    {
        if ($this->db->withinTransaction() && !$this->state->heldInThisTransaction()) {
            throw new \LogicException(
                'the palette row is taken first in a transaction (docs/internal/palette-lock-order.md); '
                . 'wrap the outer transaction in PaletteFence::within()',
            );
        }
        $this->state->ensureRow();
        $run = function () use ($work): mixed {
            $this->state->lock();
            return $work();
        };
        return $this->db->withinTransaction() ? $run() : $this->db->transaction($run);
    }
}
