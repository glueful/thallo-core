<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only envelope for the General settings show/update responses
 * ({@see \Thallo\Core\Http\Controllers\GeneralSettingsController}).
 */
final class GeneralSettingsResultData implements ResponseData
{
    public function __construct(
        public readonly GeneralSettingsData $settings,
    ) {
    }
}
