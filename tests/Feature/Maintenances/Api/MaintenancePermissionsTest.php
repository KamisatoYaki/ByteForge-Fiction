<?php

namespace Tests\Feature\Maintenances\Api;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Group;
use App\Models\Maintenance;
use App\Models\MaintenanceType;
use App\Models\User;
use Tests\TestCase;

class MaintenancePermissionsTest extends TestCase
{
    public function test_user_with_no_permissions_is_denied_index()
    {
        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.maintenances.index'))
            ->assertForbidden();
    }

    public function test_legacy_assets_view_can_index_and_show()
    {
        $maintenance = Maintenance::factory()->create();
        $actor = User::factory()->viewAssets()->create();

        $this->actingAsForApi($actor)
            ->getJson(route('api.maintenances.index'))
            ->assertOk();

        $this->actingAsForApi($actor)
            ->getJson(route('api.maintenances.show', $maintenance))
            ->assertOk();
    }

    public function test_legacy_assets_edit_can_create_update_delete_and_complete()
    {
        $actor = User::factory()->editAssets()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();
        $maintenance = Maintenance::factory()->create(['completed_at' => null]);

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $asset->id,
                'name' => 'Legacy Create',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'name' => 'Legacy Updated',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $toComplete = Maintenance::factory()->create(['completed_at' => null]);
        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.complete', $toComplete))
            ->assertOk()
            ->assertStatusMessageIs('success');

        $toDelete = Maintenance::factory()->create();
        $this->actingAsForApi($actor)
            ->deleteJson(route('api.maintenances.destroy', $toDelete))
            ->assertOk()
            ->assertStatusMessageIs('success');
        $this->assertSoftDeleted($toDelete);
    }

    public function test_maintenances_view_can_view_without_assets_view()
    {
        $maintenance = Maintenance::factory()->create();
        $actor = User::factory()->viewMaintenances()->create();

        $this->actingAsForApi($actor)
            ->getJson(route('api.maintenances.index'))
            ->assertOk();

        $this->actingAsForApi($actor)
            ->getJson(route('api.maintenances.show', $maintenance))
            ->assertOk();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $maintenance->asset_id,
                'name' => 'Should Fail',
                'start_date' => '2021-01-01',
            ])
            ->assertForbidden();
    }

    public function test_maintenances_create_can_create_without_assets_edit()
    {
        $actor = User::factory()->createMaintenances()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $asset->id,
                'name' => 'Dedicated Create',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $maintenance = Maintenance::factory()->create();
        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_maintenances_edit_can_update_without_assets_edit()
    {
        $actor = User::factory()->editMaintenances()->create();
        $maintenance = Maintenance::factory()->create();

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'name' => 'Dedicated Edit',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->actingAsForApi($actor)
            ->deleteJson(route('api.maintenances.destroy', $maintenance))
            ->assertForbidden();
    }

    public function test_maintenances_delete_can_delete_without_assets_edit()
    {
        $actor = User::factory()->deleteMaintenances()->create();
        $maintenance = Maintenance::factory()->create();

        $this->actingAsForApi($actor)
            ->deleteJson(route('api.maintenances.destroy', $maintenance))
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertSoftDeleted($maintenance);
    }

    public function test_maintenances_complete_can_complete_without_assets_edit_or_edit_ability()
    {
        $actor = User::factory()->completeMaintenances()->create();
        $maintenance = Maintenance::factory()->create(['completed_at' => null]);

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.complete', $maintenance))
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAsForApi($actor)
            ->deleteJson(route('api.maintenances.destroy', Maintenance::factory()->create()))
            ->assertForbidden();
    }

    public function test_explicit_denial_of_maintenances_edit_overrides_assets_edit()
    {
        $actor = User::factory()->create([
            'permissions' => json_encode([
                'assets.edit' => '1',
                'maintenances.edit' => '-1',
            ]),
        ]);
        $maintenance = Maintenance::factory()->create();

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'name' => 'Should Not Update',
            ])
            ->assertForbidden();
    }

    public function test_explicit_denial_of_maintenances_view_overrides_assets_view()
    {
        $actor = User::factory()->create([
            'permissions' => json_encode([
                'assets.view' => '1',
                'maintenances.view' => '-1',
            ]),
        ]);

        $this->actingAsForApi($actor)
            ->getJson(route('api.maintenances.index'))
            ->assertForbidden();
    }

    public function test_group_grant_does_not_override_explicit_user_denial()
    {
        $group = Group::factory()->create([
            'permissions' => json_encode([
                'maintenances.edit' => '1',
                'assets.edit' => '1',
            ]),
        ]);

        $actor = User::factory()->create([
            'permissions' => json_encode([
                'maintenances.edit' => '-1',
            ]),
        ]);
        $actor->groups()->attach($group->id);

        $maintenance = Maintenance::factory()->create();

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'name' => 'Denied Despite Group',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_manage_maintenances()
    {
        $actor = User::factory()->admin()->create();
        $maintenance = Maintenance::factory()->create(['completed_at' => null]);

        $this->actingAsForApi($actor)
            ->getJson(route('api.maintenances.index'))
            ->assertOk();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.complete', $maintenance))
            ->assertOk()
            ->assertStatusMessageIs('success');
    }

    public function test_fmcs_blocks_show_for_other_company_asset()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $actor = $companyA->users()->save(User::factory()->viewMaintenances()->make());

        $maintenanceB = Maintenance::factory()->create();
        $maintenanceB->asset->update(['company_id' => $companyB->id]);

        $this->actingAsForApi($actor)
            ->getJson(route('api.maintenances.show', $maintenanceB))
            ->assertStatusMessageIs('error');
    }

    public function test_bulk_api_store_checks_fmcs_on_every_asset()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $actor = $companyA->users()->save(User::factory()->createMaintenances()->make());

        $assetA = Asset::factory()->for($companyA)->create();
        $assetB = Asset::factory()->for($companyB)->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_ids' => [$assetA->id, $assetB->id],
                'name' => 'Bulk Create',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertDatabaseHas('maintenances', [
            'item_id' => $assetA->id,
            'name' => 'Bulk Create',
        ]);
        $this->assertDatabaseMissing('maintenances', [
            'item_id' => $assetB->id,
            'name' => 'Bulk Create',
        ]);
    }

    public function test_soft_deleted_maintenance_cannot_be_deleted_again()
    {
        $actor = User::factory()->deleteMaintenances()->create();
        $maintenance = Maintenance::factory()->create();
        $maintenance->delete();

        $this->actingAsForApi($actor)
            ->deleteJson(route('api.maintenances.destroy', $maintenance->id))
            ->assertStatusMessageIs('error');
    }

    public function test_already_completed_maintenance_cannot_be_completed_again()
    {
        $actor = User::factory()->completeMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'completed_at' => now()->subDay(),
            'completed_by' => User::factory()->create()->id,
        ]);

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.complete', $maintenance))
            ->assertStatusMessageIs('error');
    }

    public function test_edit_alone_cannot_complete_via_api_update()
    {
        $actor = User::factory()->editMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => null,
            'created_at' => '2021-01-01 00:00:00',
        ]);

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertForbidden();

        $this->assertNull($maintenance->fresh()->completed_at);
    }

    public function test_create_alone_cannot_create_already_completed_via_api()
    {
        $actor = User::factory()->createMaintenances()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $asset->id,
                'name' => 'Backfill Denied',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('maintenances', ['name' => 'Backfill Denied']);
    }

    public function test_complete_permission_allows_completion_via_api_update()
    {
        $actor = User::factory()->editMaintenances()->completeMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => null,
            'created_at' => '2021-01-01 00:00:00',
        ]);

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $maintenance->refresh();
        $this->assertNotNull($maintenance->completed_at);
        $this->assertSame($actor->id, $maintenance->completed_by);
    }

    public function test_explicit_complete_denial_blocks_api_update_even_with_assets_edit()
    {
        $actor = User::factory()->create([
            'permissions' => json_encode([
                'assets.edit' => '1',
                'maintenances.complete' => '-1',
            ]),
        ]);
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => null,
            'created_at' => '2021-01-01 00:00:00',
        ]);

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertForbidden();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.complete', $maintenance))
            ->assertForbidden();

        $this->assertNull($maintenance->fresh()->completed_at);
    }

    public function test_ordinary_api_edit_without_completion_change_does_not_require_complete()
    {
        $actor = User::factory()->editMaintenances()->create();
        $originalCompleter = User::factory()->create();
        $maintenance = Maintenance::factory()->create([
            'completed_at' => '2021-01-10 00:00:00',
            'completed_by' => $originalCompleter->id,
        ]);

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'name' => 'Renamed Without Touching Completion',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $maintenance->refresh();
        $this->assertEquals('Renamed Without Touching Completion', $maintenance->name);
        $this->assertNotNull($maintenance->completed_at);
        $this->assertSame($originalCompleter->id, $maintenance->completed_by);
    }

    public function test_clearing_completed_at_via_api_requires_complete_permission()
    {
        $actor = User::factory()->editMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'completed_at' => '2021-01-10 00:00:00',
            'completed_by' => User::factory()->create()->id,
        ]);

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'completed_at' => null,
            ])
            ->assertForbidden();

        $this->assertNotNull($maintenance->fresh()->completed_at);
    }
}
