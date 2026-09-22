<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Repositories\EntryRepository;
use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recomputes the media library's "Used in" index (`media_usage`) from every entry's drafts. The
 * index is kept from asset events as drafts are saved, so content saved before images inside
 * blocks were counted is missing from it; this adds what is missing and drops what no draft
 * references. Safe to re-run.
 */
#[AsCommand(
    name: 'thallo:media:rebuild-usage',
    description: 'Recompute the media library\'s "Used in" index from every entry\'s drafts',
)]
final class MediaUsageRebuildCommand extends BaseCommand
{
    private const BATCH = 200;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->getService(Connection::class);
        $entries = $this->getService(EntryRepository::class);

        $wanted = [];
        $lastId = 0;
        do {
            $rows = $db->table('entries')->select(['id', 'uuid'])
                ->where('id', '>', $lastId)->orderBy('id')->limit(self::BATCH)->get();
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $entry = (string) $row['uuid'];
                foreach ($entries->draftAssetTargetsForEntry($entry) as $blob) {
                    $wanted["{$blob}|{$entry}"] = [$blob, $entry];
                }
            }
        } while (count($rows) === self::BATCH);

        $have = [];
        foreach ($db->table('media_usage')->select(['blob_uuid', 'entry_uuid'])->get() as $row) {
            [$blob, $entry] = [(string) $row['blob_uuid'], (string) $row['entry_uuid']];
            $have["{$blob}|{$entry}"] = [$blob, $entry];
        }

        $added = 0;
        foreach (array_diff_key($wanted, $have) as [$blob, $entry]) {
            $db->table('media_usage')->insert([
                'blob_uuid' => $blob,
                'entry_uuid' => $entry,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $added++;
        }
        $removed = 0;
        foreach (array_diff_key($have, $wanted) as [$blob, $entry]) {
            $db->table('media_usage')->where('blob_uuid', '=', $blob)->where('entry_uuid', '=', $entry)->delete();
            $removed++;
        }

        $this->success(sprintf('"Used in" rebuilt: %d added, %d removed.', $added, $removed));
        return self::SUCCESS;
    }
}
