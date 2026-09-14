<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

use Thallo\Contracts\Style\StyleSchema;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Converts one document's legacy presentation fields into typed settings (visual builder spec
 * §7.2–7.3): every block of a type a pending stage touches has each ruled field converted,
 * kept, discarded, superseded (an explicit settings value already exists and wins) or reported
 * unmappable — resolved through a decision keyed by the diagnostic identity and the document
 * hash. A document is stamped with the settings schema version and the completed stages.
 */
final class Converter
{
    public const VERSION = 1;

    /** @var array<string, list<string>>|null block type slug => blocks-typed field names */
    private ?array $regions = null;

    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    /** @param list<ConversionStage> $stages the document's pending stages, in order */
    public function convert(
        DocumentRef $ref,
        array $stages,
        DecisionsFile $decisions,
        DiagnosticsReport $report,
    ): ConvertedDocument {
        $fields = $ref->fields;
        $hash = self::hash($fields);
        $unresolved = 0;
        $changed = false;
        foreach (self::blocksFields($ref->schema) as $name) {
            if (!is_array($fields[$name] ?? null)) {
                continue;
            }
            $fields[$name] = $this->convertList(
                $fields[$name],
                $stages,
                $ref,
                $hash,
                $decisions,
                $report,
                $unresolved,
                $changed,
            );
        }
        $names = array_map(static fn (ConversionStage $s): string => $s->name, $stages);
        if ($names !== []) {
            $done = array_values(array_unique([...ConversionStages::completed($ref->fields), ...$names]));
            $fields['_schema'] = ['settings' => StyleSchema::VERSION, 'conversions' => $done];
            $changed = $changed || ($ref->fields['_schema'] ?? null) !== $fields['_schema'];
        }
        return new ConvertedDocument($fields, $changed, $unresolved, $names);
    }

    /** @param array<string,mixed> $fields */
    public static function hash(array $fields): string
    {
        return sha1((string) json_encode($fields));
    }

    /**
     * @param list<mixed> $list
     * @param list<ConversionStage> $stages
     * @return list<mixed>
     */
    private function convertList(
        array $list,
        array $stages,
        DocumentRef $ref,
        string $hash,
        DecisionsFile $decisions,
        DiagnosticsReport $report,
        int &$unresolved,
        bool &$changed,
    ): array {
        foreach ($list as $i => $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $type = $block['type'];
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            $blockId = is_string($block['id'] ?? null) ? $block['id'] : "#{$i}";
            foreach ($stages as $stage) {
                foreach ($stage->rulesFor($type) as $field => $rule) {
                    if (!array_key_exists($field, $data)) {
                        continue;
                    }
                    $this->applyRule(
                        $rule,
                        $field,
                        $blockId,
                        $data,
                        $settings,
                        $ref,
                        $hash,
                        $decisions,
                        $report,
                        $unresolved,
                        $changed,
                    );
                }
            }
            foreach ($this->regionsOf($type) as $region) {
                if (is_array($data[$region] ?? null)) {
                    $data[$region] = $this->convertList(
                        $data[$region],
                        $stages,
                        $ref,
                        $hash,
                        $decisions,
                        $report,
                        $unresolved,
                        $changed,
                    );
                }
            }
            $block['data'] = $data;
            if ($settings !== []) {
                $block['settings'] = $settings;
            }
            $list[$i] = $block;
        }
        return $list;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $settings
     */
    private function applyRule(
        ConversionRule $rule,
        string $field,
        string $blockId,
        array &$data,
        array &$settings,
        DocumentRef $ref,
        string $hash,
        DecisionsFile $decisions,
        DiagnosticsReport $report,
        int &$unresolved,
        bool &$changed,
    ): void {
        $old = $data[$field];
        $outcome = $rule->apply($old, $data);
        $status = ConversionOutcome::KEPT;
        $reason = null;

        if ($outcome->kind === ConversionOutcome::UNMAPPABLE) {
            $key = DecisionsFile::key($ref->sourceType, $ref->sourceId, $ref->revision, $blockId, $field);
            $decision = $decisions->find($key, $hash, self::VERSION, $stale);
            if ($decision === null) {
                $unresolved++;
                $reason = $stale
                    ? 'decision is stale: the document changed since it was reviewed'
                    : (string) $outcome->reason;
                $report->add($this->line($ref, $blockId, $field, $old, 'unmappable', $reason, $hash));
                return;
            }
            $outcome = $this->decided($rule, $decision);
            if ($outcome === null) {
                $unresolved++;
                $reason = 'decision is not applicable';
                $report->add($this->line($ref, $blockId, $field, $old, 'unmappable', $reason, $hash));
                return;
            }
        }

        switch ($outcome->kind) {
            case ConversionOutcome::SETTING:
                $status = 'converted';
                foreach (self::pathsFor((string) $outcome->target) as $path) {
                    $segments = ['style', ...explode('.', $path)];
                    $def = StyleSchema::property($path);
                    if ($def !== null && $def->responsive) {
                        $segments[] = $outcome->breakpoint;
                    }
                    if (self::has($settings, $segments)) {
                        $status = 'superseded';
                        $reason = 'an explicit settings value already exists and wins';
                        continue;
                    }
                    $settings = self::set($settings, $segments, $outcome->value);
                }
                unset($data[$field]);
                $changed = true;
                break;
            case ConversionOutcome::ADVANCED:
                $segments = ['advanced', ...explode('.', (string) $outcome->target)];
                $settings = self::set($settings, $segments, $outcome->value);
                unset($data[$field]);
                $status = 'converted';
                $changed = true;
                break;
            case ConversionOutcome::DATA:
                if ((string) $outcome->target !== $field) {
                    unset($data[$field]);
                }
                $data[(string) $outcome->target] = $outcome->value;
                $status = 'converted';
                $changed = true;
                break;
            case ConversionOutcome::DISCARD:
                unset($data[$field]);
                $status = 'discarded';
                $changed = true;
                break;
            default:
                $status = 'kept';
        }
        $report->add($this->line($ref, $blockId, $field, $old, $status, $reason, $hash));
    }

    /**
     * A decided outcome: `token` lands where the rule says (a settings path or a data field as a
     * typed token), `value` is written verbatim there, `discard` removes the field.
     *
     * @param array{action: string, value: mixed} $decision
     */
    private function decided(ConversionRule $rule, array $decision): ?ConversionOutcome
    {
        if ($decision['action'] === 'discard') {
            return ConversionOutcome::discard();
        }
        $target = (string) $rule->decisionTarget;
        [$kind, $name] = str_contains($target, ':') ? explode(':', $target, 2) : ['', ''];
        $value = $decision['value'];
        if ($decision['action'] === 'token') {
            $domain = $rule->tokenDomain;
            $ofDomain = is_string($value) && $domain !== null && str_starts_with($value, $domain . '.');
            if (!$ofDomain) {
                return null;
            }
            $value = ['type' => 'token', 'value' => $value];
        } elseif ($decision['action'] !== 'value') {
            return null;
        }
        return match ($kind) {
            'setting' => is_array($value) ? ConversionOutcome::setting($name, $value) : null,
            'data' => ConversionOutcome::data($name, $value),
            default => null,
        };
    }

    /**
     * The settings paths an outcome writes: a property path is itself; `spacing.padding` is the
     * four sides and `spacing.margin` the two, so one decision styles a whole box.
     *
     * @return list<string>
     */
    private static function pathsFor(string $target): array
    {
        return match ($target) {
            'spacing.padding' => array_map(
                static fn (string $s): string => "spacing.padding.{$s}",
                ['top', 'right', 'bottom', 'left'],
            ),
            'spacing.margin' => ['spacing.margin.top', 'spacing.margin.bottom'],
            default => [$target],
        };
    }

    /** @return array<string,mixed> */
    private function line(
        DocumentRef $ref,
        string $blockId,
        string $field,
        mixed $old,
        string $status,
        ?string $reason,
        string $hash,
    ): array {
        return [
            'source_type' => $ref->sourceType,
            'source_id' => $ref->sourceId,
            'source_revision' => $ref->revision,
            'locale' => $ref->locale,
            'block_id' => $blockId,
            'field' => $field,
            'old_value' => $old,
            'status' => $status,
            'reason' => $reason,
            'document_hash' => $hash,
            'converter_version' => self::VERSION,
        ];
    }

    /** @return list<string> */
    private static function blocksFields(ContentTypeSchema $schema): array
    {
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type === 'blocks') {
                $names[] = $field->name;
            }
        }
        return $names;
    }

    /** @return list<string> */
    private function regionsOf(string $type): array
    {
        if ($this->regions === null) {
            $this->regions = [];
            foreach ($this->blockTypes->all() as $row) {
                $names = [];
                foreach ((array) ($row['schema'] ?? []) as $field) {
                    if (($field['type'] ?? null) === 'blocks' && is_string($field['name'] ?? null)) {
                        $names[] = $field['name'];
                    }
                }
                $this->regions[(string) $row['slug']] = $names;
            }
        }
        return $this->regions[$type] ?? [];
    }

    /**
     * @param array<string,mixed> $target
     * @param list<string> $segments
     */
    private static function has(array $target, array $segments): bool
    {
        $node = $target;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }
        return true;
    }

    /**
     * @param array<string,mixed> $target
     * @param list<string> $segments
     * @return array<string,mixed>
     */
    private static function set(array $target, array $segments, mixed $value): array
    {
        $head = array_shift($segments);
        if ($segments === []) {
            $target[$head] = $value;
            return $target;
        }
        $inner = is_array($target[$head] ?? null) ? $target[$head] : [];
        $target[$head] = self::set($inner, $segments, $value);
        return $target;
    }
}
