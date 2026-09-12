<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/**
 * Derives the normalized field list (form-block spec §3) from the contact-preset block
 * config. v1 has no field-builder: the field set is fixed core + toggled optionals, but
 * the OUTPUT is the same normalized shape a future builder will edit directly.
 */
final class FormFieldDerivation
{
    /** @param array<string,mixed> $data @return list<FieldDef> */
    public static function derive(array $data): array
    {
        $s = static fn (string $k, string $d): string =>
            is_string($data[$k] ?? null) && $data[$k] !== '' ? $data[$k] : $d;
        $on = static fn (string $k): bool => (bool) ($data[$k] ?? false);

        $fields = [];
        $fields[] = new FieldDef('name', $s('name_label', 'Name'), 'text', true, $s('name_placeholder', ''), null, []);
        if ($on('include_subject')) {
            $fields[] = new FieldDef(
                'subject',
                $s('subject_label', 'Subject'),
                'text',
                false,
                $s('subject_placeholder', ''),
                null,
                [],
            );
        }
        $fields[] = new FieldDef(
            'email',
            $s('email_label', 'Email'),
            'email',
            true,
            $s('email_placeholder', ''),
            null,
            [],
        );
        if ($on('include_phone')) {
            $fields[] = new FieldDef(
                'phone',
                $s('phone_label', 'Phone'),
                'tel',
                $on('phone_required'),
                $s('phone_placeholder', ''),
                null,
                [],
            );
        }
        $fields[] = new FieldDef(
            'message',
            $s('message_label', 'Message'),
            'textarea',
            true,
            $s('message_placeholder', ''),
            null,
            [],
        );
        if ($on('include_consent')) {
            $fields[] = new FieldDef(
                'consent',
                $s('consent_text', 'I agree to be contacted'),
                'checkbox',
                true,
                null,
                null,
                [],
            );
        }
        return $fields;
    }
}
