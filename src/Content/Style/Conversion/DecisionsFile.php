<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/**
 * The durable decisions (visual builder spec §7.3): keyed by the diagnostic identity
 * `source_type:source_id:source_revision:block_id:field`, each recording the document hash and
 * converter version it was made against, an action — `token` (a vocabulary name), `value` (a
 * typed value written verbatim), `discard` — and the value. A decision whose hash or version
 * differs from the document being converted is invalid: the document changed since review.
 */
final class DecisionsFile
{
    /** @param array<string, array<string,mixed>> $decisions */
    public function __construct(private array $decisions = [])
    {
    }

    public static function load(?string $path): self
    {
        if ($path === null || !is_file($path)) {
            return new self();
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("decisions file {$path} is not a JSON object");
        }
        $decisions = [];
        foreach ($decoded as $key => $decision) {
            if (is_string($key) && is_array($decision)) {
                $decisions[$key] = $decision;
            }
        }
        return new self($decisions);
    }

    public static function key(
        string $sourceType,
        string $sourceId,
        string $revision,
        string $blockId,
        string $field,
    ): string {
        return "{$sourceType}:{$sourceId}:{$revision}:{$blockId}:{$field}";
    }

    /**
     * The valid decision for a diagnostic, or null; `$stale` is set when one exists but its
     * hash or converter version no longer matches.
     *
     * @return array{action: string, value: mixed}|null
     */
    public function find(string $key, string $documentHash, int $converterVersion, ?bool &$stale = null): ?array
    {
        $stale = false;
        $decision = $this->decisions[$key] ?? null;
        if ($decision === null) {
            return null;
        }
        $hashMatches = ($decision['document_hash'] ?? null) === $documentHash;
        $versionMatches = (int) ($decision['converter_version'] ?? -1) === $converterVersion;
        if (!$hashMatches || !$versionMatches) {
            $stale = true;
            return null;
        }
        $action = $decision['action'] ?? null;
        if (!is_string($action)) {
            return null;
        }
        return ['action' => $action, 'value' => $decision['value'] ?? null];
    }

    /** @param array<string,mixed> $decision */
    public function record(string $key, array $decision): void
    {
        $this->decisions[$key] = $decision;
    }

    public function write(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        file_put_contents($path, json_encode($this->decisions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @return array<string, array<string,mixed>> */
    public function all(): array
    {
        return $this->decisions;
    }
}
