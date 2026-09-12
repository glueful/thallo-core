<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Support\Documentation\DocumentationUIGenerator;
use Glueful\Support\Documentation\OpenApiGenerator;

/**
 * Generates the API reference the framework's docs route serves: the OpenAPI document at
 * `documentation.paths.openapi` and the reference UI in `documentation.paths.output`. Generated
 * from the install's live routes, so it reflects that install's enabled capabilities, packs and
 * the operator's own routes; provision refreshes it on every run, exactly what
 * `php glueful generate:openapi -f --ui` writes.
 */
final class ApiReferencePublisher
{
    /** @return array{spec: string, ui: string} the two paths written */
    public function publish(ApplicationContext $context): array
    {
        $spec = (new OpenApiGenerator($context, null, null, true))->generateOpenApiSpec(true);

        $uiType = (string) config($context, 'documentation.ui.default', 'scalar');
        $ui = (new DocumentationUIGenerator($context))->generate($uiType);

        return ['spec' => $spec, 'ui' => $ui];
    }
}
