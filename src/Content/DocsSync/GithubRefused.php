<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

/** GitHub said no, or could not be reached, in words an owner can act on (docs sync design §5). */
final class GithubRefused extends \RuntimeException
{
}
