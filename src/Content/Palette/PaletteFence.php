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
            return $write($normalized->doc, $read);
        }
        return $this->within(function () use ($normalize, $write, $read, $normalized): mixed {
            $held = $this->state->lock();
            if ($held->generation !== $read->generation) {
                $normalized = $normalize($held);
            }
            return $write($normalized->doc, $held);
        });
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
