<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Contracts\Fonts\FontFamilyView;
use Thallo\Contracts\Fonts\FontLibraryReader;
use Thallo\Contracts\Style\FontStacks;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The workspace's uploaded font families (block typeface spec §2.3–§2.6). A family's ID never changes;
 * removing it is a soft delete Restore undoes, and only a removed family can be deleted permanently.
 * Faces are media-library `.woff2` files whose weight range and style are read from the file.
 *
 * **One lock order everywhere:** blob rows first — every blob an operation touches, locked up front in
 * ascending UUID order (withBlobsLocked) — then the family row, then faces, with the generation bump
 * last, all in one transaction. Media deletion takes the same blob lock (lockBlob), so a file cannot be
 * deleted while a family is being given it. Files are read before the transaction (slow), and each
 * blob is checked again under its lock. The family row is locked with an UPDATE through the query
 * builder, so the tenancy scope and write barrier apply to it like every other write; the blob lock is
 * a `SELECT … FOR UPDATE` on the framework's `blobs` table, which is not workspace-owned.
 *
 * Every mutation bumps the workspace's generation (`thallo.fonts.generation`); a snapshot is one read
 * at one generation.
 */
final class FontLibrary implements FontLibraryReader
{
    public const GENERATION_KEY = 'thallo.fonts.generation';
    private const NAME_MAX = 120;

    /** The weight range and style an unreadable face keeps (spec §2.5's compatibility declaration). */
    private const UNKNOWN_FACE = ['weight_min' => 100, 'weight_max' => 900, 'italic' => false, 'variable' => false];

    private readonly FontBlobCheck $check;

    /** @var array<string, true> blobs whose row locks the current withBlobsLocked() holds */
    private array $held = [];

    public function __construct(
        private readonly Connection $db,
        private readonly FontBlobFiles $files,
        private readonly Woff2FaceReader $reader,
        SystemFlags $flags,
        private readonly ?MediaUrlBatchResolver $urls = null,
    ) {
        $this->check = new FontBlobCheck($db, $flags);
    }

    //----------------------------------------------------------------------------------------------
    // Locks
    //----------------------------------------------------------------------------------------------

    /**
     * Runs `$work` in a transaction holding every blob's row lock, taken in ascending UUID order before
     * anything else — so two operations that need overlapping sets of files never wait on each other in
     * a cycle. Every operation touching more than one blob or family goes through here.
     *
     * @template T
     * @param list<string> $blobUuids
     * @param callable(): T $work
     * @return T
     */
    public function withBlobsLocked(array $blobUuids, callable $work): mixed
    {
        $blobs = array_values(array_unique($blobUuids));
        sort($blobs, SORT_STRING);
        $run = function () use ($blobs, $work): mixed {
            $previous = $this->held;
            try {
                foreach ($blobs as $blob) {
                    if (!isset($this->held[$blob])) {
                        $this->lockBlob($blob);
                        $this->held[$blob] = true;
                    }
                }
                return $work();
            } finally {
                $this->held = $previous;
            }
        };
        return $this->db->withinTransaction() ? $run() : $this->db->transaction($run);
    }

    /** Locks one blob row until the transaction ends (shared with media deletion). */
    public function lockBlob(string $blobUuid): void
    {
        if (!$this->db->withinTransaction()) {
            throw new \LogicException('A blob lock is only held inside a transaction');
        }
        $this->db->getPDO()->prepare('SELECT uuid FROM blobs WHERE uuid = ? FOR UPDATE')->execute([$blobUuid]);
    }

    /**
     * Locks the family row (an UPDATE, so it is scoped and barrier-checked) and returns it.
     *
     * @return array<string, mixed>
     */
    private function lockFamily(string $id): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $touched = $this->db->table('font_families')->where('id', '=', $id)->update(['updated_at' => $now]);
        $row = $touched === 0 ? null : $this->db->table('font_families')->where('id', '=', $id)->first();
        if (!is_array($row)) {
            throw FontLibraryRefusal::missing();
        }
        return $row;
    }

    private function assertHeld(string $blobUuid): void
    {
        if (!isset($this->held[$blobUuid])) {
            throw new \LogicException("Blob {$blobUuid} must be locked first (withBlobsLocked)");
        }
    }

    //----------------------------------------------------------------------------------------------
    // Mutations
    //----------------------------------------------------------------------------------------------

    /**
     * Adds a family from one or more files, each read for its faces first. An unreadable file refuses
     * the whole family.
     *
     * @param list<string> $blobUuids
     * @return string the new family's ID
     * @throws UnreadableFont|FontLibraryRefusal
     */
    public function create(string $name, string $fallback, array $blobUuids): string
    {
        $name = self::validName($name);
        self::assertFallback($fallback);
        if ($blobUuids === []) {
            throw new FontLibraryRefusal('Add at least one file', 'invalid');
        }
        if (count(array_unique($blobUuids)) !== count($blobUuids)) {
            throw new FontLibraryRefusal('That file is already in this family', 'conflict');
        }
        $faces = [];
        foreach ($blobUuids as $blob) {
            $faces[$blob] = $this->readFace($blob);
        }
        return $this->withBlobsLocked(
            array_keys($faces),
            fn (): string => $this->createWithFaces($name, $fallback, $faces),
        );
    }

    /**
     * Adds a family whose files were already read; inside withBlobsLocked() holding every one of them
     * (the upgrade creates several families in one transaction this way). A null face is a file that
     * could not be read: it is kept with the compatibility declaration and marked unknown.
     *
     * @param array<string, FaceMetadata|null> $faces blob UUID => what was read from it
     */
    public function createWithFaces(string $name, string $fallback, array $faces): string
    {
        $name = self::validName($name);
        self::assertFallback($fallback);
        if ($faces === []) {
            throw new FontLibraryRefusal('Add at least one file', 'invalid');
        }
        foreach (array_keys($faces) as $blob) {
            $this->assertHeld((string) $blob);
            $this->check->usable((string) $blob);
        }
        $id = Utils::generateNanoID(12);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('font_families')->insert([
            'id' => $id,
            'name' => $name,
            'fallback' => $fallback,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($faces as $blob => $face) {
            $this->insertFace($id, (string) $blob, $face, $now);
        }
        $this->bumpGeneration();
        return $id;
    }

    /** @throws UnreadableFont|FontLibraryRefusal */
    public function addFace(string $id, string $blobUuid): void
    {
        $face = $this->readFace($blobUuid);
        $this->withBlobsLocked([$blobUuid], function () use ($id, $blobUuid, $face): void {
            $family = $this->lockFamily($id);
            if ($family['removed_at'] !== null) {
                throw new FontLibraryRefusal('That family was removed', 'conflict');
            }
            $this->check->usable($blobUuid);
            if (in_array($blobUuid, $this->faceBlobs($id), true)) {
                throw new FontLibraryRefusal('That file is already in this family', 'conflict');
            }
            $this->insertFace($id, $blobUuid, $face, gmdate('Y-m-d H:i:s'));
            $this->bumpGeneration();
        });
    }

    /** @throws FontLibraryRefusal */
    public function removeFace(string $id, string $blobUuid): void
    {
        $this->withBlobsLocked([$blobUuid], function () use ($id, $blobUuid): void {
            $this->lockFamily($id);
            // Counted under the family lock: two removals cannot both see two faces.
            $blobs = $this->faceBlobs($id);
            if (!in_array($blobUuid, $blobs, true)) {
                throw new FontLibraryRefusal('That file isn\'t in this family', 'missing');
            }
            if (count($blobs) <= 1) {
                throw new FontLibraryRefusal('A family keeps at least one face', 'conflict');
            }
            $this->db->table('font_faces')
                ->where('family_id', '=', $id)
                ->where('blob_uuid', '=', $blobUuid)
                ->delete();
            $this->bumpGeneration();
        });
    }

    /** @throws FontLibraryRefusal */
    public function rename(string $id, string $name): void
    {
        $name = self::validName($name);
        $this->db->transaction(function () use ($id, $name): void {
            $this->lockFamily($id);
            $this->db->table('font_families')->where('id', '=', $id)->update(['name' => $name]);
            $this->bumpGeneration();
        });
    }

    /** @throws FontLibraryRefusal */
    public function setFallback(string $id, string $fallback): void
    {
        self::assertFallback($fallback);
        $this->db->transaction(function () use ($id, $fallback): void {
            $this->lockFamily($id);
            $this->db->table('font_families')->where('id', '=', $id)->update(['fallback' => $fallback]);
            $this->bumpGeneration();
        });
    }

    /** The soft delete: the family leaves the pickers and the stylesheet; its files stay protected. */
    public function remove(string $id): void
    {
        $this->db->transaction(function () use ($id): void {
            if ($this->lockFamily($id)['removed_at'] !== null) {
                return;
            }
            $this->db->table('font_families')->where('id', '=', $id)->update(['removed_at' => gmdate('Y-m-d H:i:s')]);
            $this->bumpGeneration();
        });
    }

    public function restore(string $id): void
    {
        $this->db->transaction(function () use ($id): void {
            if ($this->lockFamily($id)['removed_at'] === null) {
                return;
            }
            $this->db->table('font_families')->where('id', '=', $id)->update(['removed_at' => null]);
            $this->bumpGeneration();
        });
    }

    /** Deletes a removed family and its faces, releasing their files. */
    public function purge(string $id): void
    {
        // The face set is read first so its blobs can be locked before the family; if a face was added
        // in between, the set no longer covers it and the attempt starts again.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $blobs = $this->faceBlobs($id);
            $done = $this->withBlobsLocked($blobs, function () use ($id, $blobs): bool {
                if ($this->lockFamily($id)['removed_at'] === null) {
                    throw new FontLibraryRefusal('Remove this family before deleting it permanently', 'conflict');
                }
                if (array_diff($this->faceBlobs($id), $blobs) !== []) {
                    return false;
                }
                $this->db->table('font_faces')->where('family_id', '=', $id)->delete();
                $this->db->table('font_families')->where('id', '=', $id)->delete();
                $this->bumpGeneration();
                return true;
            });
            if ($done) {
                return;
            }
        }
        throw new \RuntimeException('The family kept changing while it was being deleted');
    }

    /**
     * Reads every face's file again, replacing what was stored — an unknown face becomes known. A file
     * that still cannot be read refuses the whole change.
     *
     * @throws UnreadableFont|FontLibraryRefusal
     */
    public function readAgain(string $id): void
    {
        $read = [];
        foreach ($this->faceBlobs($id) as $blob) {
            $read[$blob] = $this->readFace($blob);
        }
        $this->withBlobsLocked(array_keys($read), function () use ($id, $read): void {
            $this->lockFamily($id);
            foreach ($this->faceBlobs($id) as $blob) {
                if (!isset($read[$blob])) {
                    continue; // added after the read: its own faces are already from its file
                }
                $this->db->table('font_faces')
                    ->where('family_id', '=', $id)
                    ->where('blob_uuid', '=', $blob)
                    ->update(self::faceColumns($read[$blob]));
            }
            $this->bumpGeneration();
        });
    }

    //----------------------------------------------------------------------------------------------
    // Reads
    //----------------------------------------------------------------------------------------------

    /** Whether a media file is one of a family's faces — current or removed (it can't be deleted). */
    public function isLibraryBlob(string $blobUuid): bool
    {
        return $this->db->table('font_faces')->where('blob_uuid', '=', $blobUuid)->first() !== null;
    }

    /**
     * One consistent read: the generation, then the rows, then the generation again — a change that
     * commits in between moves the generation (it is bumped in the change's own transaction), and the
     * read is taken again.
     */
    public function snapshot(): FontLibrarySnapshot
    {
        $attempt = 0;
        while (true) {
            $generation = $this->generation();
            $families = $this->db->table('font_families')->get();
            $faces = $this->db->table('font_faces')->get();
            if ($this->generation() === $generation || ++$attempt >= 5) {
                return new FontLibrarySnapshot($generation, $this->views($families, $faces));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $families
     * @param list<array<string, mixed>> $faces
     * @return list<FontFamilyView>
     */
    private function views(array $families, array $faces): array
    {
        $urls = [];
        $blobs = array_values(array_unique(array_column($faces, 'blob_uuid')));
        foreach (array_chunk($blobs, 100) as $chunk) {
            $urls += $this->urls?->urls($chunk) ?? [];
        }
        $byFamily = [];
        foreach ($faces as $face) {
            $byFamily[(string) $face['family_id']][] = [
                'blob_uuid' => (string) $face['blob_uuid'],
                'url' => $urls[(string) $face['blob_uuid']] ?? '',
                'weight_min' => (int) $face['weight_min'],
                'weight_max' => (int) $face['weight_max'],
                'italic' => (bool) $face['italic'],
                'variable' => (bool) $face['variable'],
                'unknown' => (bool) $face['unknown'],
            ];
        }
        $views = [];
        foreach ($families as $family) {
            $familyFaces = $byFamily[(string) $family['id']] ?? [];
            usort($familyFaces, static fn (array $a, array $b): int => [$a['italic'], $a['weight_min'], $a['blob_uuid']]
                <=> [$b['italic'], $b['weight_min'], $b['blob_uuid']]);
            $views[] = new FontFamilyView(
                (string) $family['id'],
                (string) $family['name'],
                (string) $family['fallback'],
                $family['removed_at'] !== null,
                $familyFaces,
            );
        }
        return $views;
    }

    /** @return list<string> */
    private function faceBlobs(string $id): array
    {
        $rows = $this->db->table('font_faces')->select(['blob_uuid'])->where('family_id', '=', $id)->get();
        return array_values(array_map(static fn (array $r): string => (string) $r['blob_uuid'], $rows));
    }

    //----------------------------------------------------------------------------------------------
    // Helpers
    //----------------------------------------------------------------------------------------------

    /** Reads one blob's face from a temporary copy of its file. */
    private function readFace(string $blobUuid): FaceMetadata
    {
        $path = $this->files->localPath($blobUuid);
        try {
            return $this->reader->read($path);
        } finally {
            @unlink($path);
        }
    }

    private function insertFace(string $familyId, string $blobUuid, ?FaceMetadata $face, string $now): void
    {
        $this->db->table('font_faces')->insert([
            'id' => Utils::generateNanoID(12),
            'family_id' => $familyId,
            'blob_uuid' => $blobUuid,
            'created_at' => $now,
        ] + self::faceColumns($face));
    }

    /** @return array{weight_min: int, weight_max: int, italic: bool, variable: bool, unknown: bool} */
    private static function faceColumns(?FaceMetadata $face): array
    {
        if ($face === null) {
            return self::UNKNOWN_FACE + ['unknown' => true];
        }
        return [
            'weight_min' => $face->weightMin,
            'weight_max' => $face->weightMax,
            'italic' => $face->italic,
            'variable' => $face->variable,
            'unknown' => false,
        ];
    }

    private function generation(): int
    {
        $row = $this->db->table('settings')->select(['value'])->where('key', '=', self::GENERATION_KEY)->first();
        return is_array($row) ? (int) $row['value'] : 0;
    }

    /**
     * Increments the generation inside the change's transaction. Its row is locked by the UPDATE, so
     * concurrent changes to different families each add one; the first change in a workspace inserts it.
     */
    private function bumpGeneration(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $touched = $this->db->table('settings')
            ->where('key', '=', self::GENERATION_KEY)
            ->update(['updated_at' => $now]);
        if ($touched === 0) {
            $this->db->table('settings')->insert(['key' => self::GENERATION_KEY, 'value' => '1', 'updated_at' => $now]);
            return;
        }
        $this->db->table('settings')
            ->where('key', '=', self::GENERATION_KEY)
            ->update(['value' => (string) ($this->generation() + 1)]);
    }

    private static function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new FontLibraryRefusal('Name the family', 'invalid');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new FontLibraryRefusal('Keep the name under ' . self::NAME_MAX . ' characters', 'invalid');
        }
        return $name;
    }

    private static function assertFallback(string $fallback): void
    {
        if (!in_array($fallback, FontStacks::FALLBACKS, true)) {
            throw new FontLibraryRefusal('Choose a fallback', 'invalid');
        }
    }
}
