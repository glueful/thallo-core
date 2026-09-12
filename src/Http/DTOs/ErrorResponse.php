<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder for the framework's standard error envelope.
 *
 * Mirrors exactly what {@see \Glueful\Http\Response::error()} emits on every failed
 * request: `success: false`, a human-readable `message`, and a nested `error` object
 * (see {@see ErrorDetail}). It is NEVER returned at runtime — the framework builds error
 * responses itself; this class exists only so the OpenAPI reflect generator can attach a
 * typed schema to the documented 4xx/5xx `#[ApiResponse]`s (with `envelope: false`, since
 * this IS the body, not a wrapped success payload).
 */
final class ErrorResponse implements ResponseData
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ErrorDetail $error,
    ) {
    }
}
