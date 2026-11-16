<?php

namespace Tests\Unit\Helpers;

use App\Helpers\MaintenanceAuthorization;
use App\Models\Group;
use App\Models\User;
use Tests\TestCase;

class MaintenanceAuthorizationTest extends TestCase
{
    public function test_default_deny_when_no_permissions()
    {
        $user = User::factory()->create();

        $this->assertFalse(MaintenanceAuthorization::allowsView($user));
        $this->assertFalse(MaintenanceAuthorization::allowsCreate($user));
        $this->assertFalse(MaintenanceAuthorization::allowsEdit($user));
        $this->assertFalse(MaintenanceAuthorization::allowsDelete($user));
        $this->assertFalse(MaintenanceAuthorization::allowsComplete($user));
    }

    public function test_legacy_assets_view_allows_view_only()
    {
        $user = User::factory()->viewAssets()->create();

        $this->assertTrue(MaintenanceAuthorization::allowsView($user));
        $this->assertFalse(MaintenanceAuthorization::allowsCreate($user));
        $this->assertFalse(MaintenanceAuthorization::allowsEdit($user));
        $this->assertFalse(MaintenanceAuthorization::allowsDelete($user));
        $this->assertFalse(MaintenanceAuthorization::allowsComplete($user));
    }

    public function test_legacy_assets_edit_allows_mutating_abilities()
    {
        $user = User::factory()->editAssets()->create();

        $this->assertFalse(MaintenanceAuthorization::allowsView($user));
        $this->assertTrue(MaintenanceAuthorization::allowsCreate($user));
        $this->assertTrue(MaintenanceAuthorization::allowsEdit($user));
        $this->assertTrue(MaintenanceAuthorization::allowsDelete($user));
        $this->assertTrue(MaintenanceAuthorization::allowsComplete($user));
    }

    public function test_dedicated_permissions_do_not_cross_grant()
    {
        $viewer = User::factory()->viewMaintenances()->create();
        $this->assertTrue(MaintenanceAuthorization::allowsView($viewer));
        $this->assertFalse(MaintenanceAuthorization::allowsCreate($viewer));
        $this->assertFalse(MaintenanceAuthorization::allowsEdit($viewer));
        $this->assertFalse(MaintenanceAuthorization::allowsDelete($viewer));
        $this->assertFalse(MaintenanceAuthorization::allowsComplete($viewer));

        $completer = User::factory()->completeMaintenances()->create();
        $this->assertTrue(MaintenanceAuthorization::allowsComplete($completer));
        $this->assertFalse(MaintenanceAuthorization::allowsView($completer));
        $this->assertFalse(MaintenanceAuthorization::allowsCreate($completer));
        $this->assertFalse(MaintenanceAuthorization::allowsEdit($completer));
        $this->assertFalse(MaintenanceAuthorization::allowsDelete($completer));
    }

    public function test_explicit_user_denial_overrides_legacy_assets_edit()
    {
        $user = User::factory()->editAssets()->create([
            'permissions' => json_encode([
                'assets.edit' => '1',
                'maintenances.edit' => '-1',
            ]),
        ]);

        $this->assertTrue(MaintenanceAuthorization::userExplicitlyDenies($user, 'maintenances.edit'));
        $this->assertFalse(MaintenanceAuthorization::allowsEdit($user));
        $this->assertTrue(MaintenanceAuthorization::allowsCreate($user));
        $this->assertTrue(MaintenanceAuthorization::allowsDelete($user));
        $this->assertTrue(MaintenanceAuthorization::allowsComplete($user));
    }

    public function test_explicit_user_denial_overrides_legacy_assets_view()
    {
        $user = User::factory()->create([
            'permissions' => json_encode([
                'assets.view' => '1',
                'maintenances.view' => '-1',
            ]),
        ]);

        $this->assertFalse(MaintenanceAuthorization::allowsView($user));
    }

    public function test_explicit_user_denial_overrides_permission_group_grant()
    {
        $group = Group::factory()->create([
            'permissions' => json_encode([
                'maintenances.edit' => '1',
                'assets.edit' => '1',
            ]),
        ]);

        $user = User::factory()->create([
            'permissions' => json_encode([
                'maintenances.edit' => '-1',
            ]),
        ]);
        $user->groups()->attach($group->id);
        $user->load('groups');

        $this->assertFalse(MaintenanceAuthorization::allowsEdit($user));
    }

    public function test_superuser_is_always_allowed()
    {
        $user = User::factory()->superuser()->create();

        $this->assertTrue(MaintenanceAuthorization::allowsView($user));
        $this->assertTrue(MaintenanceAuthorization::allowsCreate($user));
        $this->assertTrue(MaintenanceAuthorization::allowsEdit($user));
        $this->assertTrue(MaintenanceAuthorization::allowsDelete($user));
        $this->assertTrue(MaintenanceAuthorization::allowsComplete($user));
    }

    public function test_completed_at_would_change_detects_set_clear_and_correct()
    {
        $this->assertTrue(MaintenanceAuthorization::completedAtWouldChange(null, '2021-01-06 00:00:00'));
        $this->assertTrue(MaintenanceAuthorization::completedAtWouldChange('2021-01-06 00:00:00', null));
        $this->assertTrue(MaintenanceAuthorization::completedAtWouldChange('2021-01-06 00:00:00', '2021-01-10 00:00:00'));
        $this->assertFalse(MaintenanceAuthorization::completedAtWouldChange(null, null));
        $this->assertFalse(MaintenanceAuthorization::completedAtWouldChange(null, ''));
        $this->assertFalse(MaintenanceAuthorization::completedAtWouldChange('2021-01-06 00:00:00', '2021-01-06 00:00:00'));
    }

    public function test_explicit_complete_denial_blocks_legacy_assets_edit_fallback()
    {
        $user = User::factory()->create([
            'permissions' => json_encode([
                'assets.edit' => '1',
                'maintenances.complete' => '-1',
            ]),
        ]);

        $this->assertFalse(MaintenanceAuthorization::allowsComplete($user));
        $this->assertTrue(MaintenanceAuthorization::allowsEdit($user));
    }
}
