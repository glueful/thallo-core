<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Http\Response;

/** A palette refusal as the 422 every writer returns (custom palette spec §4.5). */
final class PaletteRefusalResponse
{
    public static function from(PaletteRefusal $e): Response
    {
        return Response::validation(['palette' => $e->errors]);
    }
}
