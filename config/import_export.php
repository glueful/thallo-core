<?php

/**
 * Import/Export defaults (shadows glueful/import-export's own config so a new key in a new
 * release reaches every install).
 *
 * `source_roots` is deliberately EMPTY here. The admin's import upload writes to the site's
 * storage/uploads, and the import service takes a root as an absolute path — which only a file
 * that lives in the site can compute. This file ships inside the thallo-core package, at
 * vendor/glueful/thallo-core/config/ on an install: a path built from __DIR__ here pointed into
 * vendor/, and every admin-started import failed to find its file. The site's own
 * config/import_export.php (shipped by the skeleton) sets the root; it wins key by key.
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
