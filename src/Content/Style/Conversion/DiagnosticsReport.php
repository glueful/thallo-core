<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/**
 * The dry run's diagnostics (visual builder spec §7.3): one JSON line per legacy field seen,
 * with a source-generic identity (source type, id and revision, locale, block id, field), the
 * old value, its status — converted, kept, unmappable, superseded, discarded — a reason, the
 * document hash the decision must match, and the converter version.
 */
final class DiagnosticsReport
{
    public const STATUSES = ['converted', 'kept', 'unmappable', 'superseded', 'discarded', 'changed'];

    /** @var list<array<string,mixed>> */
    private array $lines = [];

    /** @param array<string,mixed> $line */
    public function add(array $line): void
    {
        $this->lines[] = $line;
    }

    /** @return list<array<string,mixed>> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<array<string,mixed>> the diagnostics still needing a decision */
    public function unresolved(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (array $l): bool => in_array($l['status'], ['unmappable', 'changed'], true),
        ));
    }

    /** @return array<string,int> status => count */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->lines as $line) {
            $counts[$line['status']] = ($counts[$line['status']] ?? 0) + 1;
        }
        return $counts;
    }

    public function write(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $out = '';
        foreach ($this->lines as $line) {
            $out .= json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        }
        if (@file_put_contents($path, $out) === false) {
            throw new \RuntimeException("cannot write {$path}");
        }
    }
}
