<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Best-effort email notification for a submission (form-block spec §10). Every failure mode is
 * non-fatal: no bound sender, an invalid recipient or a throwing sender only return false. The
 * result is what the caller needs: an "email only" form keeps the submission when nothing went.
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
     * @return bool whether the notification was handed off to the mail transport
     */
    public function notify(FormDescriptor $descriptor, array $values, ?string $sourceUrl): bool
    {
        if ($this->sender === null) {
            return false;
        }
        // Re-validate at send time (defense in depth; the seal already validated it).
        if (filter_var($descriptor->recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('form notification skipped: invalid recipient', [
                'form_key' => $descriptor->formKey,
            ]);
            return false;
        }

        $subject = 'New ' . $descriptor->formName . ' submission';
        try {
            $this->sender->send($descriptor->recipient, $subject, $this->body($descriptor, $values, $sourceUrl));
            return true;
        } catch (Throwable $e) {
            $this->logger->error('form notification failed', [
                'form_key' => $descriptor->formKey,
                'error' => $e->getMessage(),
            ]);
            return false;
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
