<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * The recovery choices an operation offers, computed from the runner's private journal (spec
 * §16.2): roll the code back while any forward rename is live; retry provision only while the whole
 * swap is live and none of it reversed; reopen always. Outside recovery, none.
 */
final class RecoveryOptions
{
    private const SWAP = ['swap.1', 'swap.2', 'swap.3', 'swap.4'];

    /** @return list<string> */
    public static function for(array $op): array
    {
        if (($op['status'] ?? '') !== Status::RECOVERY_REQUIRED) {
            return [];
        }
        $journal = (array) ($op['journal'] ?? []);
        $reversed = [];
        foreach ($journal as $e) {
            if (($e['kind'] ?? '') === 'reverse' && ($e['phase'] ?? '') === 'done') {
                $reversed[(int) $e['reverses']] = true;
            }
        }
        $live = [];
        foreach ($journal as $e) {
            $forward = ($e['kind'] ?? '') === 'forward' && ($e['phase'] ?? '') === 'done';
            if ($forward && !isset($reversed[(int) $e['seq']])) {
                $live[] = (string) $e['label'];
            }
        }
        $options = [];
        if ($live !== []) {
            $options[] = 'rollback';
        }
        if (array_diff(self::SWAP, $live) === []) {
            $options[] = 'retry_provision';
        }
        $options[] = 'reopen';
        return $options;
    }
}
