<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Storage\StorageManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thallo-owned HTTP glue for the glueful/import-export extension.
 *
 * The extension owns the job API (under /import-export) but ships no route to (a) download an
 * export's result file or (b) upload an import source file. This controller fills both gaps:
 *  - download(): concatenates a completed export's `result` files (NDJSON is line-delimited, so
 *    concatenation stays valid) and streams them as an attachment.
 *  - upload(): writes an .ndjson import file to the `uploads` disk under import-export/ and returns
 *    {disk, path} to hand to POST /import-export/imports. It bypasses the framework's media uploader
 *    (which rejects non-media MIME types). The disk root is aligned via config/import_export.php.
 *
 * Both are gated by `content.manage`.
 */
final class ImportExportController
{
    private const UPLOAD_DIR = 'import-export';
    private const ALLOWED_EXTENSIONS = ['ndjson', 'jsonl', 'json'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ImportExportJobRepository $jobs,
        private readonly ImportExportFileRepository $files,
        private readonly StorageManager $storage,
    ) {
    }

    /** GET /v1/admin/import-export/jobs/{uuid}/download */
    #[ApiOperation(
        summary: 'Download an export result',
        description: 'Streams the NDJSON result of a completed export job (concatenating its result '
            . 'files). Requires `content.manage`.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(200, description: 'The export result file (application/x-ndjson).')]
    #[ApiResponse(404, description: 'No such export job, or no result is available yet.')]
    public function download(string $uuid): HttpResponse
    {
        $job = $this->jobs->find($uuid);
        if ($job === null) {
            return Response::notFound('Job not found.');
        }
        if ((string) ($job['type'] ?? '') !== 'export') {
            return Response::notFound('Only export jobs have a downloadable result.');
        }

        $files = $this->files->forJob($uuid, 'result');
        if ($files === []) {
            return Response::notFound('No export result is available yet.');
        }

        $storage = $this->storage;
        $filename = 'export-' . $uuid . '.ndjson';

        $response = new StreamedResponse(static function () use ($files, $storage): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            foreach ($files as $file) {
                $path = isset($file['path']) ? (string) $file['path'] : '';
                if ($path === '') {
                    continue;
                }
                $disk = isset($file['disk']) ? (string) $file['disk'] : null;
                $stream = $storage->disk($disk)->readStream($path);
                stream_copy_to_stream($stream, $out);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }

    /** POST /v1/admin/import-export/upload */
    #[ApiOperation(
        summary: 'Upload an import file',
        description: 'Stores an NDJSON import source file on the uploads disk and returns its '
            . '{disk, path} for POST /import-export/imports. Requires `content.manage`.',
        tags: ['Import Export'],
    )]
    #[ApiResponse(201, description: 'Stored; returns disk + path.')]
    #[ApiResponse(422, description: 'Missing file, wrong type, or too large.')]
    public function upload(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return Response::validation(['file' => 'A file upload is required.']);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return Response::validation(['file' => 'Only .ndjson, .jsonl, or .json files are accepted.']);
        }

        $maxSize = (int) config($this->context, 'import_export.max_file_size', 52428800);
        $size = $file->getSize();
        if ($size !== null && $size > $maxSize) {
            return Response::validation(['file' => 'The file exceeds the maximum allowed size.']);
        }

        $path = self::UPLOAD_DIR . '/' . Utils::generateNanoID(16) . '.ndjson';
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            return Response::error('Could not read the uploaded file.', Response::HTTP_BAD_REQUEST);
        }
        $this->storage->putStream($path, $stream, 'uploads');
        if (is_resource($stream)) {
            fclose($stream);
        }

        return Response::created([
            'disk' => 'uploads',
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
        ], 'Import file uploaded.');
    }
}
