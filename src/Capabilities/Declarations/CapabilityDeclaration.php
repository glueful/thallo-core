<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Declarations;

use Thallo\Contracts\Capability\Capability;

/** One capability as one source declared it: `provider:<class>` or `package:<name>`. */
final class CapabilityDeclaration
{
    public function __construct(
        public readonly Capability $capability,
        public readonly string $source,
    ) {
    }
}
