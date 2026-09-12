<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Best-effort email notification for a stored submission (form-block spec §10). Every
 * failure mode is non-fatal: no bound sender → no-op; an invalid recipient → skip; a
 * throwing sender → logged and swallowed. The submission is already persisted by the
 * time this runs, so a mail outage never loses data.
 */
final class FormNotifier
{
    public function __construct(
        private readonly ?FormMailSender $sender,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $values normalized submitted values keyed by field key
     */
    public function notify(FormDescriptor $descriptor, array $values, ?string $sourceUrl): void
    {
        if ($this->sender === null) {
            return; // no email capability bound — storage is the source of truth
        }
        // Re-validate at send time (defense in depth; the seal already validated it).
        if (filter_var($descriptor->recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('form notification skipped: invalid recipient', [
                'form_key' => $descriptor->formKey,
            ]);
            return;
        }

        $subject = 'New ' . $descriptor->formName . ' submission';
        try {
            $this->sender->send($descriptor->recipient, $subject, $this->body($descriptor, $values, $sourceUrl));
        } catch (Throwable $e) {
            $this->logger->error('form notification failed', [
                'form_key' => $descriptor->formKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A plain-text "Label: value" body in the sealed field order, plus the source URL.
     *
     * @param array<string,mixed> $values
     */
    private function body(FormDescriptor $descriptor, array $values, ?string $sourceUrl): string
    {
        $lines = [];
        foreach ($descriptor->fields as $field) {
            $raw = $values[$field->key] ?? '';
            $display = is_bool($raw) ? ($raw ? 'Yes' : 'No') : (string) $raw;
            $lines[] = $field->label . ': ' . $display;
        }
        if ($sourceUrl !== null && $sourceUrl !== '') {
            $lines[] = '';
            $lines[] = 'Submitted from: ' . $sourceUrl;
        }
        return implode("\n", $lines);
    }
}
