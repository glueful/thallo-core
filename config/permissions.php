<?php

/*
 * Permission defaults Thallo contributes to the framework's `permissions` config. A site's own
 * config/permissions.php overrides them key by key.
 */
return [
    // Thallo enforces every permission as `content_permission:<slug>[,<slug>…]` route middleware.
    // Naming it here lets `php glueful permissions:diff` count those permissions as enforced.
    'enforcing_middleware' => ['content_permission'],
];
