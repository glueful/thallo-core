<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/**
 * An editor controller's palette fields (custom palette plan Task 12): its load responses carry the
 * generation read with the document, its save responses the rewrites, the held generation and the
 * replacement records newer than the editor's boundary. Without the palette services (a minimal
 * container) the fields are still present, empty, so the response shape never varies.
 *
 * The using class declares `?PaletteResponseFields $paletteFields`.
 */
trait CarriesPaletteFields
{
    /**
     * @template T
     * @param callable(): T $read
     * @return array{0: T, 1: array{palette_generation: int}}
     */
    private function paletteLoad(callable $read): array
    {
        return $this->paletteFields?->forLoad($read) ?? [$read(), ['palette_generation' => 0]];
    }

    /**
     * @template T
     * @param callable(): T $save
     * @return array{0: T, 1: array<string,mixed>}
     */
    private function paletteSave(callable $save, ?int $through): array
    {
        return $this->paletteFields?->save($save, $through) ?? [$save(), [
            'palette_rewrites' => [],
            'palette_generation' => 0,
            'palette_replacements' => ['after' => 0, 'through' => 0, 'records' => []],
        ]];
    }
}
