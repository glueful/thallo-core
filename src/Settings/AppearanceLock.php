<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Database\Connection;

/**
 * The workspace's appearance lock (block typeface spec §2.7): taken by every writer of the Custom
 * Text and Headings assignments — a settings save and the one-time upgrade — before it reads or writes
 * one, so the upgrade's "only where nothing is stored" decision can never race a save. A row lock on
 * the workspace's own `thallo.fonts.appearance_lock` settings row, through the query builder (scoped
 * like every settings write). Lock order relative to the font library: this lock, then blob rows,
 * then family rows.
 */
final class AppearanceLock
{
    public const KEY = 'thallo.fonts.appearance_lock';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Runs `$work` in a transaction holding the lock (inside an open transaction it still locks).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function within(callable $work): mixed
    {
        if (!$this->db->withinTransaction()) {
            $this->ensure();
        }
        $run = function () use ($work): mixed {
            $this->db->table('settings')->where('key', '=', self::KEY)->update(['updated_at' => gmdate('Y-m-d H:i:s')]);
            return $work();
        };
        return $this->db->withinTransaction() ? $run() : $this->db->transaction($run);
    }

    /**
     * Makes sure the lock row exists, outside any transaction: two first uses may race to insert it,
     * and the loser's duplicate key must not abort a transaction it is part of.
     */
    public function ensure(): void
    {
        if ($this->db->table('settings')->where('key', '=', self::KEY)->first() !== null) {
            return;
        }
        try {
            $this->db->table('settings')->insert([
                'key' => self::KEY,
                'value' => '',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            if ($this->db->table('settings')->where('key', '=', self::KEY)->first() === null) {
                throw $e;
            }
        }
    }
}
