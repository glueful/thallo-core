<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

/**
 * A library change the rules refuse; the message is the sentence shown to the person making it.
 * `missing` is a family that does not exist here; `invalid` a name, fallback or file list the form
 * should correct; `conflict` a rule the library's current state forbids (the last face, a file twice).
 */
final class FontLibraryRefusal extends \RuntimeException
{
    /** @param 'missing'|'invalid'|'conflict' $kind */
    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self('That typeface doesn\'t exist', 'missing');
    }
}
