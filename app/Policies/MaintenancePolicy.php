<?php

namespace App\Policies;

use App\Helpers\MaintenanceAuthorization;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Maintenance;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Policy for Asset Maintenances.
 *
 * Prefers dedicated maintenances.* permissions (deny-aware) with a
 * legacy assets.view / assets.edit fallback when the dedicated key is
 * unset. Instance-level abilities also require FMCS access to the
 * parent asset.
 *
 * Admin short-circuit mirrors SnipePermissionsPolicy / AssetPolicy so
 * existing administrator behavior for maintenance records is preserved
 * when controllers authorize Maintenance abilities. Superuser is
 * handled globally in AuthServiceProvider::boot().
 */
final class MaintenancePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability, mixed $item = null): ?bool
    {
        if ($user->hasAccess('admin')) {
            return true;
        }

        return null;
    }

    public function index(User $user): bool
    {
        return MaintenanceAuthorization::allowsView($user);
    }

    public function view(User $user, mixed $maintenance = null): bool
    {
        if ($maintenance instanceof Maintenance) {
            return $this->canAccessParentAsset($maintenance)
                && MaintenanceAuthorization::allowsView($user);
        }

        return MaintenanceAuthorization::allowsView($user);
    }

    /**
     * @param  mixed  $asset  Optional parent Asset when authorizing create-on-asset
     */
    public function create(User $user, mixed $asset = null): bool
    {
        if ($asset instanceof Asset) {
            if (! Company::isCurrentUserHasAccess($asset)) {
                return false;
            }
        }

        if ($asset instanceof Maintenance) {
            return $this->canAccessParentAsset($asset)
                && MaintenanceAuthorization::allowsCreate($user);
        }

        return MaintenanceAuthorization::allowsCreate($user);
    }

    public function update(User $user, mixed $maintenance = null): bool
    {
        if ($maintenance instanceof Maintenance) {
            return $this->canAccessParentAsset($maintenance)
                && MaintenanceAuthorization::allowsEdit($user);
        }

        return MaintenanceAuthorization::allowsEdit($user);
    }

    public function delete(User $user, mixed $maintenance = null): bool
    {
        if ($maintenance instanceof Maintenance) {
            return empty($maintenance->deleted_at)
                && $this->canAccessParentAsset($maintenance)
                && MaintenanceAuthorization::allowsDelete($user);
        }

        return MaintenanceAuthorization::allowsDelete($user);
    }

    public function complete(User $user, mixed $maintenance = null): bool
    {
        if ($maintenance instanceof Maintenance) {
            return $this->canAccessParentAsset($maintenance)
                && MaintenanceAuthorization::allowsComplete($user);
        }

        return MaintenanceAuthorization::allowsComplete($user);
    }

    public function files(User $user, Maintenance $maintenance): bool
    {
        return $this->canAccessParentAsset($maintenance)
            && MaintenanceAuthorization::allowsEdit($user);
    }

    public function manageFiles(User $user, Maintenance $maintenance): bool
    {
        return $this->canAccessParentAsset($maintenance)
            && MaintenanceAuthorization::allowsEdit($user);
    }

    public function history(User $user, Maintenance $maintenance): bool
    {
        return Gate::allows('view', $maintenance)
            || $user->hasAccess('activity.view');
    }

    public function journal(User $user, Maintenance $maintenance): bool
    {
        return Gate::allows('view', $maintenance)
            || $user->hasAccess('activity.view');
    }

    private function canAccessParentAsset(Maintenance $maintenance): bool
    {
        $asset = $maintenance->asset;

        return $asset instanceof Model && Company::isCurrentUserHasAccess($asset);
    }
}
