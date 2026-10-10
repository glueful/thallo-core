<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/**
 * The palette fields editor responses carry (custom palette plan Task 12): a load's generation, read
 * consistently with its document; a save's rewrites, the generation it held, and the complete batch of
 * replacement records the editor has not seen, bounded above by that generation.
 */
final class PaletteResponseFields
{
    public function __construct(
        private readonly PaletteState $state,
        private readonly PaletteReplacements $replacements,
        private readonly ?PaletteFence $fence = null,
    ) {
    }

    /**
     * Run a save and build its palette fields from what its fenced writes did.
     *
     * @template T
     * @param callable(): T $save
     * @return array{0: T, 1: array{palette_rewrites: list<array<string,string>>, palette_generation: int,
     *     palette_replacements: array<string,mixed>}}
     */
    public function save(callable $save, ?int $through): array
    {
        if ($this->fence === null) {
            return [$save(), $this->forSave([], $through)];
        }
        [$result, $outcome] = $this->fence->capture($save);
        return [$result, $this->forSave($outcome?->rewrites ?? [], $through, $outcome?->generation)];
    }

    /**
     * Run a load's document read consistently with the palette generation.
     *
     * @template T
     * @param callable(): T $read
     * @return array{0: T, 1: array{palette_generation: int}}
     */
    public function forLoad(callable $read): array
    {
        [$result, $generation] = $this->state->consistentRead($read);
        return [$result, ['palette_generation' => $generation]];
    }

    /**
     * @param list<array{location: string, from: string, to: string}> $rewrites
     * @param int|null $through the editor's contiguous ledger boundary (the request's `palette_through`)
     * @param int|null $held the generation the write held; the current one when the write was not fenced
     * @return array{palette_rewrites: list<array<string,string>>, palette_generation: int,
     *     palette_replacements: array<string,mixed>}
     */
    public function forSave(array $rewrites, ?int $through, ?int $held = null): array
    {
        $generation = $held !== null && $held > 0 ? $held : $this->state->generationNow();
        try {
            $batch = $this->replacements->batch($through ?? $generation, $generation);
        } catch (PaletteHistoryExpired) {
            $batch = ['expired' => true];
        }
        return [
            'palette_rewrites' => $rewrites,
            'palette_generation' => $generation,
            'palette_replacements' => $batch,
        ];
    }
}
