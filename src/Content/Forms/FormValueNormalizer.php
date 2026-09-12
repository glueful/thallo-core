<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/**
 * Validates + normalizes raw request input against the sealed FieldDef[] (form-block
 * spec §6). ONLY sealed fields are considered — unknown request keys are dropped, so a
 * visitor cannot smuggle extra fields. Values are normalized on the way in (checkbox →
 * bool, text trimmed) so what we store and email is canonical, never the raw request bag.
 */
final class FormValueNormalizer
{
    private const MAX_LENGTH = 5000;

    /**
     * @param list<FieldDef> $fields
     * @param array<string,mixed> $raw
     * @return array{values: array<string,mixed>, errors: array<string,string>}
     */
    public static function normalize(array $fields, array $raw): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $f) {
            $in = $raw[$f->key] ?? null;
            if ($f->type === 'checkbox') {
                $checked = $in !== null && $in !== '' && $in !== '0' && $in !== false;
                if ($f->required && !$checked) {
                    $errors[$f->key] = 'Required.';
                }
                $values[$f->key] = $checked;
                continue;
            }
            $val = is_string($in) ? trim($in) : '';
            if ($val === '') {
                if ($f->required) {
                    $errors[$f->key] = 'Required.';
                }
                $values[$f->key] = '';
                continue;
            }
            if ($f->type === 'email' && filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$f->key] = 'Enter a valid email address.';
            }
            if ($f->type === 'select' && $f->options !== [] && !in_array($val, $f->options, true)) {
                $errors[$f->key] = 'Choose a valid option.';
            }
            if (mb_strlen($val) > self::MAX_LENGTH) {
                $errors[$f->key] = 'Too long.';
            }
            $values[$f->key] = $val;
        }
        return ['values' => $values, 'errors' => $errors];
    }
}
