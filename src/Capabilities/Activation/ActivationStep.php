<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/** The steps of a capability activation, in order (feature activation spec §3.2). */
final class ActivationStep
{
    /** Completed by ActivationStore::startOrJoin, with the capability published off. */
    public const MARK_PREPARING = 'mark_preparing';
    /** Changes provider activation: the invocation that runs it stops at the boot boundary. */
    public const ENABLE_ENGINE = 'enable_engine';
    public const VERIFY_BOOT = 'verify_boot';
    public const SEED_BLOCKS = 'seed_blocks';
    public const GRANT_PERMISSIONS = 'grant_permissions';
    public const FINALIZE = 'finalize';

    public const ALL = [
        self::MARK_PREPARING,
        self::ENABLE_ENGINE,
        self::VERIFY_BOOT,
        self::SEED_BLOCKS,
        self::GRANT_PERMISSIONS,
        self::FINALIZE,
    ];

    /** Steps that write application files (config/, bootstrap/cache). */
    public const WRITES_APPLICATION_FILES = [self::ENABLE_ENGINE];
}
