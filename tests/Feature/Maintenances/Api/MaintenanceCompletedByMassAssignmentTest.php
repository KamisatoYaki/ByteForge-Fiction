<?php

namespace Tests\Feature\Maintenances\Api;

use App\Models\Asset;
use App\Models\Maintenance;
use App\Models\MaintenanceType;
use App\Models\User;
use Tests\TestCase;

/**
 * completed_by is an attribution column. Clients must never assign it;
 * store/update stamp auth()->id() only on an authorized transition into
 * the completed state. The dedicated complete() / bulk-complete paths
 * remain the other legitimate writers.
 */
class MaintenanceCompletedByMassAssignmentTest extends TestCase
{
    public function test_patch_cannot_set_completed_by_to_another_user()
    {
        $editor = User::factory()->editAssets()->create();
        $otherAdmin = User::factory()->superuser()->create();

        $asset = Asset::factory()->create();
        $maintenance = Maintenance::factory()->create([
            'asset_id' => $asset->id,
            'completed_by' => null,
        ]);

        $this->actingAsForApi($editor)
            ->patchJson(route('api.maintenances.update', $maintenance->id), [
                'completed_by' => $otherAdmin->id,
            ]);

        $maintenance->refresh();

        $this->assertNull(
            $maintenance->completed_by,
            'completed_by must stay null when it was null before. The update endpoint must not accept a client-supplied value for this attribution column.'
        );
    }

    public function test_patch_does_not_overwrite_existing_completed_by()
    {
        $editor = User::factory()->editAssets()->create();
        $existingCompleter = User::factory()->superuser()->create();
        $otherAdmin = User::factory()->superuser()->create();

        $asset = Asset::factory()->create();
        $maintenance = Maintenance::factory()->create([
            'asset_id' => $asset->id,
            'completed_by' => $existingCompleter->id,
        ]);

        $this->actingAsForApi($editor)
            ->patchJson(route('api.maintenances.update', $maintenance->id), [
                'completed_by' => $otherAdmin->id,
                'notes' => 'editing other legitimate fields',
            ]);

        $maintenance->refresh();

        $this->assertSame(
            $existingCompleter->id,
            $maintenance->completed_by,
            'An existing completed_by must survive a PATCH that attempts to overwrite it.'
        );
    }

    public function test_api_store_cannot_assign_another_employee_as_completed_by()
    {
        $actor = User::factory()->createMaintenances()->completeMaintenances()->create();
        $other = User::factory()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $asset->id,
                'name' => 'Forged Completer Denied',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
                'completed_at' => '2021-01-06 00:00:00',
                'completed_by' => $other->id,
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $maintenance = Maintenance::where('name', 'Forged Completer Denied')->firstOrFail();

        $this->assertSame($actor->id, $maintenance->completed_by);
        $this->assertNotSame($other->id, $maintenance->completed_by);
    }

    public function test_authorized_api_store_with_completion_records_authenticated_user()
    {
        $actor = User::factory()->createMaintenances()->completeMaintenances()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $asset->id,
                'name' => 'Authenticated Completer',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $maintenance = Maintenance::where('name', 'Authenticated Completer')->firstOrFail();

        $this->assertNotNull($maintenance->completed_at);
        $this->assertSame($actor->id, $maintenance->completed_by);
    }

    public function test_api_update_completion_records_authenticated_user()
    {
        $actor = User::factory()->editMaintenances()->completeMaintenances()->create();
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => null,
            'completed_by' => null,
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

    public function test_ordinary_update_does_not_overwrite_original_completed_by()
    {
        $actor = User::factory()->editMaintenances()->create();
        $originalCompleter = User::factory()->create();
        $maintenance = Maintenance::factory()->create([
            'name' => 'Already Done',
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => '2021-01-10 00:00:00',
            'completed_by' => $originalCompleter->id,
        ]);

        $this->assertTrue($maintenance->exists);
        $this->assertNotNull($maintenance->id);

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'name' => 'Already Done Renamed',
                'completed_by' => $actor->id,
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $maintenance->refresh();
        $this->assertSame('Already Done Renamed', $maintenance->name);
        $this->assertSame($originalCompleter->id, $maintenance->completed_by);
    }

    public function test_correcting_completed_at_does_not_overwrite_original_completed_by()
    {
        $actor = User::factory()->editMaintenances()->completeMaintenances()->create();
        $originalCompleter = User::factory()->create();
        $maintenance = Maintenance::factory()->create([
            'start_date' => '2021-01-01 00:00:00',
            'completed_at' => '2021-01-10 00:00:00',
            'completed_by' => $originalCompleter->id,
            'created_at' => '2021-01-01 00:00:00',
        ]);

        $this->actingAsForApi($actor)
            ->putJson(route('api.maintenances.update', $maintenance), [
                'completed_at' => '2021-01-15 00:00:00',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $maintenance->refresh();
        $this->assertEquals('2021-01-15 00:00:00', $maintenance->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame($originalCompleter->id, $maintenance->completed_by);
    }

    public function test_unauthorized_completion_via_store_remains_blocked()
    {
        $actor = User::factory()->createMaintenances()->create();
        $other = User::factory()->create();
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $asset->id,
                'name' => 'Unauthorized Complete',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
                'completed_at' => '2021-01-06 00:00:00',
                'completed_by' => $other->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('maintenances', ['name' => 'Unauthorized Complete']);
    }

    public function test_explicit_complete_denial_overrides_assets_edit_for_store_completion()
    {
        $actor = User::factory()->create([
            'permissions' => json_encode([
                'assets.edit' => '1',
                'maintenances.complete' => '-1',
            ]),
        ]);
        $asset = Asset::factory()->create();
        $type = MaintenanceType::factory()->create();

        $this->actingAsForApi($actor)
            ->postJson(route('api.maintenances.store'), [
                'asset_id' => $asset->id,
                'name' => 'Denied Despite Assets Edit',
                'maintenance_type_id' => $type->id,
                'start_date' => '2021-01-01',
                'completed_at' => '2021-01-06 00:00:00',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('maintenances', ['name' => 'Denied Despite Assets Edit']);
    }
}
