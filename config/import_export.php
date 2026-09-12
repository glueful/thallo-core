<?php

/**
 * Import/Export configuration (shadows glueful/import-export's own config).
 *
 * The only deviation from the extension defaults is `source_roots`: the `uploads` disk physically
 * lives at storage/uploads, but the importer resolves an `uploads`-disk path against base/uploads by
 * default. Pointing the root at the real location lets files written by the admin import-upload
 * endpoint (POST /v1/admin/import-export/upload) resolve correctly. All other keys mirror the
 * extension so this shadow file doesn't drop any defaults.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2); // the repo root (this file lives in core/config)

return [
    'routes_enabled' => true,
    'source_disk' => 'uploads',
    'source_roots' => [
        'uploads' => $root . '/storage/uploads',
    ],
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
