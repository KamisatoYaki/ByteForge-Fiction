<?php

namespace App\Helpers;

use App\Models\User;
use Carbon\Carbon;

/**
 * Deny-aware resolution for ByteForge maintenance permissions.
 *
 * Prefer maintenances.{ability}; honor explicit user-level denial (-1)
 * over legacy assets.* fallbacks; fall back to assets.view / assets.edit
 * only when the dedicated key is unset (not denied).
 *
 * Does not change User::hasAccess() semantics. Group-level -1 is not
 * treated as deny here, matching checkPermissionSection().
 */
class MaintenanceAuthorization
{
    /**
     * Whether the user's own permissions JSON explicitly denies a key.
     */
    public static function userExplicitlyDenies(User $user, string $permission): bool
    {
        $permissions = $user->decodePermissions();

        if ($permissions instanceof \stdClass) {
            $permissions = (array) $permissions;
        }

        if (! is_array($permissions)) {
            return false;
        }

        return array_key_exists($permission, $permissions)
            && (int) $permissions[$permission] === -1;
    }

    /**
     * Resolve a maintenance ability with dedicated-prefer + legacy fallback.
     *
     * @param  string  $ability  view|create|edit|delete|complete (maps to maintenances.{ability})
     */
    public static function allows(User $user, string $ability, bool $legacyFallback): bool
    {
        if ($user->isSuperUser()) {
            return true;
        }

        $permission = 'maintenances.'.$ability;

        if (self::userExplicitlyDenies($user, $permission)) {
            return false;
        }

        if ($user->hasAccess($permission)) {
            return true;
        }

        return $legacyFallback;
    }

    public static function allowsView(User $user): bool
    {
        return self::allows($user, 'view', $user->hasAccess('assets.view'));
    }

    public static function allowsCreate(User $user): bool
    {
        return self::allows($user, 'create', $user->hasAccess('assets.edit'));
    }

    public static function allowsEdit(User $user): bool
    {
        return self::allows($user, 'edit', $user->hasAccess('assets.edit'));
    }

    public static function allowsDelete(User $user): bool
    {
        return self::allows($user, 'delete', $user->hasAccess('assets.edit'));
    }

    public static function allowsComplete(User $user): bool
    {
        return self::allows($user, 'complete', $user->hasAccess('assets.edit'));
    }

    /**
     * Whether a requested completed_at value differs from the persisted one.
     * Empty string and null both mean "not completed."
     */
    public static function completedAtWouldChange(mixed $persistedCompletedAt, mixed $requestedCompletedAt): bool
    {
        return self::normalizeCompletedAt($persistedCompletedAt) !== self::normalizeCompletedAt($requestedCompletedAt);
    }

    /**
     * Whether a companion completion field (completed_by / duration) would change.
     */
    public static function completionMetadataWouldChange(mixed $persisted, mixed $requested): bool
    {
        $current = ($persisted === null || $persisted === '') ? null : (string) $persisted;
        $incoming = ($requested === null || $requested === '') ? null : (string) $requested;

        return $current !== $incoming;
    }

    public static function normalizeCompletedAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
