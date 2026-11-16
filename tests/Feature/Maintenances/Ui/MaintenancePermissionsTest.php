<?php

namespace Tests\Feature\Maintenances\Ui;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Maintenance;
use App\Models\MaintenanceType;
use App\Models\User;
use Tests\TestCase;

class MaintenancePermissionsTest extends TestCase
{
    public function test_index_requires_permission()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('maintenances.index'))
            ->assertForbidden();
    }

    public function test_legacy_assets_view_can_open_index_and_show()
    {
        $maintenance = Maintenance::factory()->create();
        $actor = User::factory()->viewAssets()->create();

        $this->actingAs($actor)
            ->get(route('maintenances.index'))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('maintenances.show', $maintenance))
            ->assertOk();
    }

    public function test_maintenances_view_can_open_index_without_assets_view()
    {
        $this->actingAs(User::factory()->viewMaintenances()->create())
            ->get(route('maintenances.index'))
            ->assertOk();
    }

    public function test_maintenances_create_can_open_create_form_without_assets_edit()
    {
        $this->actingAs(User::factory()->createMaintenances()->create())
            ->get(route('maintenances.create'))
            ->assertOk();
    }

    public function test_maintenances_edit_can_open_edit_form_without_assets_edit()
    {
        $maintenance = Maintenance::factory()->create();

        $this->actingAs(User::factory()->editMaintenances()->create())
            ->get(route('maintenances.edit', $maintenance))
            ->assertOk();
    }

    public function test_maintenances_complete_can_complete_without_assets_edit()
    {
        $actor = User::factory()->completeMaintenances()->create();
        $maintenance = Maintenance::factory()->create(['completed_at' => null]);

        $this->actingAs($actor)
            ->post(route('maintenances.complete', $maintenance))
            ->assertRedirect();

        $maintenance->refresh();
        $this->assertNotNull($maintenance->completed_at);

        $this->actingAs($actor)
            ->get(route('maintenances.edit', $maintenance))
            ->assertForbidden();
    }

    public function test_maintenances_delete_can_destroy_without_assets_edit()
    {
        $maintenance = Maintenance::factory()->create();

        $this->actingAs(User::factory()->deleteMaintenances()->create())
            ->delete(route('maintenances.destroy', $maintenance))
            ->assertRedirect(route('maintenances.index'));

        $this->assertSoftDeleted($maintenance);
    }

    public function test_explicit_denial_blocks_legacy_edit_access()
    {
        $actor = User::factory()->create([
            'permissions' => json_encode([
                'assets.edit' => '1',
                'maintenances.edit' => '-1',
            ]),
        ]);
        $maintenance = Maintenance::factory()->create();

        $this->actingAs($actor)
            ->get(route('maintenances.edit', $maintenance))
            ->assertForbidden();
    }

    public function test_admin_can_access_maintenance_index()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('maintenances.index'))
            ->assertOk();
    }

    public function test_superuser_can_access_maintenance_index()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('maintenances.index'))
            ->assertOk();
    }

    public function test_fmcs_blocks_show_for_other_company()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $actor = $companyA->users()->save(User::factory()->viewMaintenances()->make());

        $maintenanceB = Maintenance::factory()->create();
        $maintenanceB->asset->update(['company_id' => $companyB->id]);

        $this->actingAs($actor)
            ->get(route('maintenances.show', $maintenanceB))
            ->assertRedirectToRoute('maintenances.index');
    }

    public function test_bulk_delete_requires_delete_permission()
    {
        $maintenance = Maintenance::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('maintenances.bulk'), [
                'bulk_actions' => 'delete',
                'ids' => [$maintenance->id],
            ])
            ->assertForbidden();
    }

    public function test_bulk_complete_allows_complete_permission_without_assets_edit()
    {
        $actor = User::factory()->completeMaintenances()->create();
        $maintenance = Maintenance::factory()->create(['completed_at' => null]);

        $this->actingAs($actor)
            ->post(route('maintenances.bulk'), [
                'bulk_actions' => 'complete',
                'ids' => [$maintenance->id],
            ])
            ->assertRedirect(route('maintenances.index'))
            ->assertSessionHas('success');

        $maintenance->refresh();
        $this->assertNotNull($maintenance->completed_at);
    }

    public function test_store_creates_with_dedicated_create_permission()
    {
        $actor = User::factory()->createMaintenances()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAs($actor)
            ->post(route('maintenances.store'), [
                'name' => 'UI Dedicated Create',
                'selected_assets' => [$asset->id],
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01 00:00:00',
            ])
            ->assertRedirect(route('maintenances.index'));

        $this->assertDatabaseHas('maintenances', [
            'name' => 'UI Dedicated Create',
            'item_id' => $asset->id,
        ]);
    }

    public function test_edit_alone_cannot_complete_via_web_update()
    {
        $actor = User::factory()->editMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => null,
            'created_at' => '2021-01-01 00:00:00',
        ]);

        $this->actingAs($actor)
            ->put(route('maintenances.update', $maintenance), [
                'name' => $maintenance->name,
                'asset_id' => $maintenance->asset_id,
                'maintenance_type_id' => $maintenance->maintenance_type_id,
                'start_date' => '2021-01-01 00:00:00',
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertForbidden();

        $this->assertNull($maintenance->fresh()->completed_at);
    }

    public function test_create_alone_cannot_create_already_completed_via_web()
    {
        $actor = User::factory()->createMaintenances()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAs($actor)
            ->post(route('maintenances.store'), [
                'name' => 'UI Backfill Denied',
                'selected_assets' => [$asset->id],
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01 00:00:00',
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('maintenances', ['name' => 'UI Backfill Denied']);
    }

    public function test_complete_with_edit_allows_web_update_completion()
    {
        $actor = User::factory()->editMaintenances()->completeMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => null,
            'created_at' => '2021-01-01 00:00:00',
        ]);

        $this->actingAs($actor)
            ->put(route('maintenances.update', $maintenance), [
                'name' => $maintenance->name,
                'asset_id' => $maintenance->asset_id,
                'maintenance_type_id' => $maintenance->maintenance_type_id,
                'start_date' => '2021-01-01 00:00:00',
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNotNull($maintenance->fresh()->completed_at);
    }

    public function test_explicit_complete_denial_blocks_web_completion_even_with_assets_edit()
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

        $this->actingAs($actor)
            ->put(route('maintenances.update', $maintenance), [
                'name' => $maintenance->name,
                'asset_id' => $maintenance->asset_id,
                'maintenance_type_id' => $maintenance->maintenance_type_id,
                'start_date' => '2021-01-01 00:00:00',
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->post(route('maintenances.complete', $maintenance))
            ->assertForbidden();

        $this->assertNull($maintenance->fresh()->completed_at);
    }

    public function test_ordinary_web_edit_without_completion_change_does_not_require_complete()
    {
        $actor = User::factory()->editMaintenances()->create();
        $completer = User::factory()->create();
        $maintenance = Maintenance::factory()->create([
            'name' => 'Still Complete',
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => '2021-01-10 00:00:00',
            'completed_by' => $completer->id,
            'asset_maintenance_time' => 9,
        ]);

        $this->actingAs($actor)
            ->put(route('maintenances.update', $maintenance), [
                'name' => 'Renamed Still Complete',
                'asset_id' => $maintenance->asset_id,
                'maintenance_type_id' => $maintenance->maintenance_type_id,
                'start_date' => '2021-01-01 00:00:00',
                'completed_at' => '2021-01-10 00:00:00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $maintenance->refresh();
        $this->assertEquals('Renamed Still Complete', $maintenance->name);
        $this->assertEquals('2021-01-10 00:00:00', $maintenance->completed_at->format('Y-m-d H:i:s'));
        $this->assertEquals($completer->id, $maintenance->completed_by);
    }

    public function test_clearing_completed_at_via_web_requires_complete_permission()
    {
        $actor = User::factory()->editMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => '2021-01-10 00:00:00',
            'completed_by' => User::factory()->create()->id,
            'asset_maintenance_time' => 9,
        ]);

        $this->actingAs($actor)
            ->put(route('maintenances.update', $maintenance), [
                'name' => $maintenance->name,
                'asset_id' => $maintenance->asset_id,
                'maintenance_type_id' => $maintenance->maintenance_type_id,
                'start_date' => '2021-01-01 00:00:00',
                'completed_at' => '',
            ])
            ->assertForbidden();

        $this->assertNotNull($maintenance->fresh()->completed_at);
    }

    public function test_fmcs_still_blocks_completion_for_other_company()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $actor = $companyA->users()->save(
            User::factory()->editMaintenances()->completeMaintenances()->make()
        );

        $maintenanceB = Maintenance::factory()->create(['completed_at' => null]);
        $maintenanceB->asset->update(['company_id' => $companyB->id]);

        $this->actingAs($actor)
            ->post(route('maintenances.complete', $maintenanceB))
            ->assertRedirectToRoute('maintenances.index');

        $this->assertNull($maintenanceB->fresh()->completed_at);
    }
}
