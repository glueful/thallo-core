<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * The admin's menus and landing page, set per role and per user (Users & Access). Tidying only:
 * a hidden menu's page is still the user's to open when their permissions allow it.
 *
 * What a user gets ({@see self::resolve()}): an item is hidden when any of their active roles
 * hides it, and their own setting — `hidden` or `shown` — beats their roles'. The landing page is
 * their own, else the one set by their highest-level role that sets one, else none (Home).
 */
final class AdminUiSettings
{
    public const ROLE = 'role';
    public const USER = 'user';
    public const HIDDEN = 'hidden';
    public const SHOWN = 'shown';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{menus: array<string,string>, landing: ?string} */
    public function get(string $type, string $uuid): array
    {
        $row = $this->db->table('admin_ui_settings')
            ->where('subject_type', '=', $type)
            ->where('subject_uuid', '=', $uuid)
            ->first();
        return self::decode($row);
    }

    /**
     * Replace a role's or user's settings; nothing set removes the row.
     *
     * @param array<string,string> $menus path => hidden|shown
     */
    public function put(string $type, string $uuid, array $menus, ?string $landing, ?string $by): void
    {
        $table = $this->db->table('admin_ui_settings');
        $existing = $table->where('subject_type', '=', $type)->where('subject_uuid', '=', $uuid)->first();
        if ($menus === [] && $landing === null) {
            if ($existing !== null) {
                $this->db->table('admin_ui_settings')->where('id', '=', (string) $existing['id'])->delete();
            }
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'menus' => json_encode((object) $menus, JSON_THROW_ON_ERROR),
            'landing' => $landing,
            'updated_by' => $by,
            'updated_at' => $now,
        ];
        if ($existing !== null) {
            $this->db->table('admin_ui_settings')->where('id', '=', (string) $existing['id'])->update($values);
            return;
        }
        $this->db->table('admin_ui_settings')->insert($values + [
            'id' => Utils::generateNanoID(),
            'subject_type' => $type,
            'subject_uuid' => $uuid,
            'created_at' => $now,
        ]);
    }

    /** @return array{hidden: list<string>, landing: ?string} what the user's sidebar and sign-in follow */
    public function resolve(string $userUuid): array
    {
        $roles = $this->activeRoles($userUuid);
        $settings = [];
        if ($roles !== []) {
            $rows = $this->db->table('admin_ui_settings')
                ->where('subject_type', '=', self::ROLE)
                ->whereIn('subject_uuid', array_keys($roles))
                ->get();
            foreach ($rows as $row) {
                $settings[(string) $row['subject_uuid']] = self::decode($row);
            }
        }
        $hidden = [];
        $landing = null;
        $landingLevel = null;
        foreach ($settings as $roleUuid => $role) {
            foreach ($role['menus'] as $path => $state) {
                if ($state === self::HIDDEN) {
                    $hidden[$path] = true;
                }
            }
            if ($role['landing'] !== null && ($landingLevel === null || $roles[$roleUuid] > $landingLevel)) {
                $landing = $role['landing'];
                $landingLevel = $roles[$roleUuid];
            }
        }
        $own = $this->get(self::USER, $userUuid);
        foreach ($own['menus'] as $path => $state) {
            if ($state === self::HIDDEN) {
                $hidden[$path] = true;
            } else {
                unset($hidden[$path]);
            }
        }
        return ['hidden' => array_keys($hidden), 'landing' => $own['landing'] ?? $landing];
    }

    public function roleExists(string $uuid): bool
    {
        return $this->db->table('roles')->where('uuid', '=', $uuid)->whereNull('deleted_at')->first() !== null;
    }

    public function userExists(string $uuid): bool
    {
        return $this->db->table('users')->where('uuid', '=', $uuid)->whereNull('deleted_at')->first() !== null;
    }

    /** @return array<string,int> the user's active, unexpired roles: uuid => level */
    private function activeRoles(string $userUuid): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $uuids = [];
        foreach ($this->db->table('user_roles')->where('user_uuid', '=', $userUuid)->get() as $row) {
            $expires = $row['expires_at'] ?? null;
            if ($expires === null || (string) $expires >= $now) {
                $uuids[] = (string) $row['role_uuid'];
            }
        }
        if ($uuids === []) {
            return [];
        }
        $out = [];
        $rows = $this->db->table('roles')
            ->whereIn('uuid', $uuids)
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->get();
        foreach ($rows as $row) {
            $out[(string) $row['uuid']] = (int) ($row['level'] ?? 0);
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array{menus: array<string,string>, landing: ?string}
     */
    private static function decode(?array $row): array
    {
        if ($row === null) {
            return ['menus' => [], 'landing' => null];
        }
        $menus = is_string($row['menus'] ?? null) ? json_decode($row['menus'], true) : ($row['menus'] ?? []);
        $clean = [];
        foreach (is_array($menus) ? $menus : [] as $path => $state) {
            if (in_array($state, [self::HIDDEN, self::SHOWN], true)) {
                $clean[(string) $path] = $state;
            }
        }
        $landing = $row['landing'] ?? null;
        return ['menus' => $clean, 'landing' => is_string($landing) && $landing !== '' ? $landing : null];
    }
}
