<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Glueful\Database\Connection;
use Thallo\Core\Settings\AppearanceLock;
use Thallo\Core\Settings\GeneralSettings;

/**
 * Folds the old Custom uploads into the font library, once per workspace (block typeface spec §2.7):
 * the text and headings files (`theme_font_body`, `theme_font_display`, media library uuids) become
 * families — `Site text`, `Site headings`, or one `Site font` when both name the same file — with the
 * `system-ui` fallback today's stack ends in, and Custom's Text and Headings assignments name them.
 *
 * Untouched-only: an assignment is written only where nothing is stored; a stored empty string is
 * someone's deliberate clear and is left. `theme_font` is never changed (an unselected Custom gets its
 * families and assignments, inactive until chosen). Files are read before the transaction; then, under
 * the appearance lock and holding every file's blob lock, the families, the assignments and the marker
 * are written in one transaction — a crash leaves none of them, so the next run starts clean. A file
 * the media library no longer has (deleted, not a font) is skipped, as the page skips it today; one it
 * cannot parse becomes an unknown face with the compatibility declaration (spec §2.5).
 */
final class FontLibraryUpgrade
{
    public const MARKER = 'thallo.fonts.custom_migrated';
    private const LEGACY = ['text' => 'theme_font_body', 'headings' => 'theme_font_display'];
    private const ASSIGNMENT = ['text' => 'theme_font_text_family', 'headings' => 'theme_font_headings_family'];

    public function __construct(
        private readonly Connection $db,
        private readonly FontLibrary $library,
        private readonly FontBlobFiles $files,
        private readonly Woff2FaceReader $reader,
        private readonly FontBlobCheck $check,
        private readonly GeneralSettings $settings,
        private readonly AppearanceLock $lock,
    ) {
    }

    /** @return array{created: int, assigned: array{text: bool, headings: bool}} */
    public function run(): array
    {
        $result = ['created' => 0, 'assigned' => ['text' => false, 'headings' => false]];
        if ($this->settings->storedValue(self::MARKER) !== null) {
            return $result;
        }

        // Read every file first (slow): what each role names, and what each file holds.
        $roles = [];
        foreach (self::LEGACY as $role => $key) {
            $uuid = $this->settings->storedValue($key) ?? '';
            if ($uuid !== '' && $this->usable($uuid)) {
                $roles[$role] = $uuid;
            }
        }
        $faces = [];
        foreach (array_unique(array_values($roles)) as $uuid) {
            $faces[$uuid] = $this->read($uuid);
        }

        return $this->lock->within(fn (): array => $this->library->withBlobsLocked(
            array_keys($faces),
            function () use ($roles, $faces, $result): array {
                if ($this->settings->storedValue(self::MARKER) !== null) {
                    return $result; // another run finished first
                }
                $families = [];
                foreach ($faces as $uuid => $face) {
                    $named = array_keys($roles, $uuid, true);
                    $name = count($named) > 1 ? 'Site font' : ($named[0] === 'text' ? 'Site text' : 'Site headings');
                    $families[$uuid] = $this->library->createWithFaces($name, 'system-ui', [$uuid => $face]);
                    $result['created']++;
                }
                $writes = [];
                foreach ($roles as $role => $uuid) {
                    // Untouched only: absent is null; a deliberate clear is a stored ''.
                    if ($this->settings->storedValue(self::ASSIGNMENT[$role]) === null) {
                        $writes[self::ASSIGNMENT[$role]] = $families[$uuid];
                        $result['assigned'][$role] = true;
                    }
                }
                $this->settings->save($writes);
                $this->db->table('settings')->insert([
                    'key' => self::MARKER,
                    'value' => '1',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                return $result;
            },
        ));
    }

    private function usable(string $uuid): bool
    {
        try {
            $this->check->usable($uuid);
            return true;
        } catch (UnreadableFont) {
            return false;
        }
    }

    /** The file's face, or null when it cannot be read (kept as an unknown face). */
    private function read(string $uuid): ?FaceMetadata
    {
        try {
            $path = $this->files->localPath($uuid);
        } catch (UnreadableFont) {
            return null;
        }
        try {
            return $this->reader->read($path);
        } catch (UnreadableFont) {
            return null;
        } finally {
            @unlink($path);
        }
    }
}
