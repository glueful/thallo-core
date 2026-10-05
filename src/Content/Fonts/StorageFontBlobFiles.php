<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Storage\StorageManager;

/**
 * Copies a stored font blob to a temporary file, as the framework's UploadController::readToTempFile()
 * does for image variants, after checking it is one of this workspace's font files.
 */
final class StorageFontBlobFiles implements FontBlobFiles
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly StorageManager $storage,
        private readonly FontBlobCheck $check,
    ) {
    }

    public function localPath(string $blobUuid): string
    {
        $blob = $this->check->usable($blobUuid);
        $disk = (string) ($blob['storage_type'] ?? '') !== ''
            ? (string) $blob['storage_type']
            : (string) config($this->context, 'uploads.disk', 'uploads');
        try {
            $stream = $this->storage->disk($disk)->readStream((string) ($blob['url'] ?? ''));
        } catch (\Throwable $e) {
            throw new UnreadableFont(FontBlobCheck::NOT_IN_LIBRARY, $e);
        }
        if (!is_resource($stream)) {
            throw new UnreadableFont(FontBlobCheck::NOT_IN_LIBRARY);
        }
        $temp = tempnam(sys_get_temp_dir(), 'font_');
        $out = $temp === false ? false : fopen($temp, 'w');
        if ($temp === false || $out === false) {
            fclose($stream);
            throw new \RuntimeException('Failed to create a temporary font file');
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
        return $temp;
    }
}
