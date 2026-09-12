<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/** One-pass render result: the sealed token plus the descriptor it sealed (form-block spec §4/§6). */
final class SealedForm
{
    public function __construct(
        public readonly string $token,
        public readonly FormDescriptor $descriptor,
    ) {
    }
}
