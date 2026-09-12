<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * The nested `error` object inside the framework's standard error envelope
 * ({@see ErrorResponse}). Carries the fields {@see \Glueful\Http\Response::error()}
 * always populates: the HTTP status `code`, an ISO-8601 `timestamp`, and the
 * `request_id` correlation id used to trace the request in logs.
 *
 * Doc-only — never constructed at runtime.
 */
final class ErrorDetail implements ResponseData
{
    public function __construct(
        public readonly int $code,
        public readonly string $timestamp,
        public readonly string $request_id,
    ) {
    }
}
