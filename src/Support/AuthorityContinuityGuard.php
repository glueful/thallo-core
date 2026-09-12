<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use Glueful\Database\Connection;

/** Serializes authority changes and prevents the final authority holder from disappearing. */
final class AuthorityContinuityGuard
{
    private const LOCK_KEY = 'thallo:authority';

    public function __construct(
        private readonly Connection $db,
        private readonly RoleAuthority $authority,
        private readonly AuthorityAudit $audit,
    ) {
    }

    public function runExclusive(callable $operation): mixed
    {
        try {
            return $this->db->transaction(function () use ($operation) {
                $stmt = $this->db->getPDO()->prepare(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))'
                );
                $stmt->execute(['lock_key' => self::LOCK_KEY]);
                return $operation();
            });
        } catch (AuthorityContinuityViolation $e) {
            $this->audit->record('security.authority_change_denied', $e->actorUuid, $e->targetUuid, [
                'operation' => $e->operation,
                'outcome' => 'denied',
                'reason' => $e->reason,
            ]);
            throw RoleAssignmentException::forbidden($e->getMessage());
        }
    }

    /** @param list<string> $rolesRemoved */
    public function assertPreservesAuthority(
        ?string $actorUuid,
        string $targetUuid,
        array $rolesRemoved,
        bool $deactivatingOrDeleting,
        string $operation,
    ): void {
        if ($this->authority->isCanonicalSuperuser($targetUuid)) {
            $losesSuperuser = $deactivatingOrDeleting
                || in_array(RoleAuthority::SUPERUSER, $rolesRemoved, true);
            if ($losesSuperuser && $this->authority->activeSuperuserCount() <= 1) {
                throw new AuthorityContinuityViolation(
                    $actorUuid,
                    $targetUuid,
                    $operation,
                    'last_superuser',
                );
            }
        }

        $accessRoles = $this->authority->targetCrossWorkspaceRoleSlugs($targetUuid);
        if ($accessRoles === []) {
            return;
        }
        $losesCrossWorkspace = $deactivatingOrDeleting
            || array_diff($accessRoles, $rolesRemoved) === [];
        if ($losesCrossWorkspace && $this->authority->activeCrossWorkspaceHolderCount() <= 1) {
            throw new AuthorityContinuityViolation(
                $actorUuid,
                $targetUuid,
                $operation,
                'last_cross_workspace_holder',
            );
        }
    }
}
