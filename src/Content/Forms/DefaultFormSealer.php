<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

use Glueful\Encryption\EncryptionService;
use Thallo\Contracts\Content\FormSealer;

final class DefaultFormSealer implements FormSealer
{
    private const AAD = 'form.descriptor';

    /** @param callable(array<string,mixed>):list<FieldDef> $derive */
    public function __construct(
        private readonly EncryptionService $encryption,
        private $derive,
        private readonly int $cacheTtl,
        private readonly int $maxAge,
        private readonly int $buffer,
        private readonly string $defaultRecipient,
        private readonly int $minSeconds,
    ) {
    }

    public function describe(array $block, ?array $entry, ?string $currentPath, ?string $regionSlug): ?SealedForm
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $recipient = $this->resolveRecipient($data);
        if ($recipient === null) {
            return null; // un-routable → no descriptor (spec §4)
        }
        $fields = ($this->derive)($data);
        if ($fields === []) {
            return null;
        }
        $redirect = $this->safeRedirect(is_string($data['redirect_url'] ?? null) ? $data['redirect_url'] : null);
        $blockId = is_string($block['id'] ?? null) ? $block['id'] : 'anon';
        $source = FormSourceIdentity::resolve($entry, $regionSlug, $currentPath);
        $issued = time();

        $descriptor = new FormDescriptor(
            v: FormDescriptor::VERSION,
            formKey: hash('sha256', $source . '|' . $blockId),
            formName: is_string($data['form_name'] ?? null) && $data['form_name'] !== '' ? $data['form_name'] : 'Form',
            fields: $fields,
            recipient: $recipient,
            successMessage: is_string($data['success_message'] ?? null) && $data['success_message'] !== ''
                ? $data['success_message'] : 'Thanks — your message has been sent.',
            redirectUrl: $redirect,
            honeypotField: 'website_' . substr(hash('sha256', $blockId . $source), 0, 8),
            minSeconds: $this->minSeconds, // real config dependency — time-trap armed at seal time
            spamVersion: 1,
            issuedAt: $issued,
            delivery: ($data['delivery'] ?? null) === FormDescriptor::DELIVERY_EMAIL_ONLY
                ? FormDescriptor::DELIVERY_EMAIL_ONLY
                : FormDescriptor::DELIVERY_STORE_AND_EMAIL,
        );

        $token = $this->encryption->encrypt(
            (string) json_encode($descriptor->toArray() + ['exp' => $issued + $this->lifetime()]),
            aad: self::AAD,
        );
        return new SealedForm($token, $descriptor); // render reads the descriptor here — no re-open
    }

    public function open(string $token): ?FormDescriptor
    {
        if ($token === '' || !$this->encryption->isEncrypted($token)) {
            return null;
        }
        try {
            $json = $this->encryption->decrypt($token, aad: self::AAD);
        } catch (\Throwable) {
            return null; // tamper / wrong key
        }
        $a = json_decode($json, true);
        if (!is_array($a) || (int) ($a['exp'] ?? 0) < time()) {
            return null; // malformed or expired
        }
        return FormDescriptor::fromArray($a);
    }

    /** @param array<string,mixed> $data */
    private function resolveRecipient(array $data): ?string
    {
        $candidate = is_string($data['recipient'] ?? null) && $data['recipient'] !== ''
            ? $data['recipient'] : $this->defaultRecipient;
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false ? $candidate : null;
    }

    /**
     * ROOT-RELATIVE internal URLs only (spec §6, P1) — a single leading slash, not
     * protocol-relative ("//host"). Rejects schemes, hosts, bare relatives
     * ("contact/thanks"), query-only ("?thanks=1") and fragment-only ("#thanks").
     * This is deliberately the strictest safe set; never an open redirect.
     */
    private function safeRedirect(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match('#\A/(?!/)[^\s]*\z#', $url) === 1) {
            return $url;
        }
        return null;
    }

    private function lifetime(): int
    {
        return max($this->maxAge, $this->cacheTtl + $this->buffer);
    }
}
