<?php

/**
 * Import/Export defaults (shadows glueful/import-export's own config so a new key in a new
 * release reaches every install).
 *
 * `source_roots` is deliberately EMPTY here: this file ships inside the thallo-core package, at
 * vendor/glueful/thallo-core/config/ on an install, and cannot know where the site is. A path built
 * from __DIR__ here pointed into vendor/, and every admin-started import failed to find its file.
 * CoreServiceProvider fills the `uploads` root from the uploads disk's own root when it merges
 * these defaults; a site that sets it in its config/import_export.php still wins.
 */

declare(strict_types=1);

return [
    'routes_enabled' => true,
    'source_disk' => 'uploads',
    'source_roots' => [],
    'result_disk' => 'local',
    'private_path' => null,
    'tmp_disk' => 'local',
    'tmp_path' => 'import-export/tmp',
    'queue' => 'import-export',
    'batch_size' => 500,
    'max_batches_per_job' => 10000,
    'max_file_size' => 52428800,
    'retention_days' => 30,
    'error_cap_per_severity' => 1000,
    'stale_lock_minutes' => 15,
];
