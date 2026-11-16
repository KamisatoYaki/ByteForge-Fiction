<?php

namespace Tests\Feature\PurchaseRequests\Ui;

use App\Actions\PurchaseRequests\CreatePurchaseRequestAction;
use App\Actions\PurchaseRequests\SubmitPurchaseRequestAction;
use App\Enums\PurchaseRequestStatus;
use App\Models\Company;
use App\Models\Group;
use App\Models\PurchaseRequest;
use App\Models\User;
use Tests\TestCase;

class PurchaseRequestPermissionsTest extends TestCase
{
    /**
     * @return list<array{description: string, quantity: int, estimated_unit_cost: string}>
     */
    private function sampleItems(): array
    {
        return [[
            'description' => 'Widget',
            'quantity' => 1,
            'estimated_unit_cost' => '25.0000',
        ]];
    }

    private function createDraftFor(User $requester, array $overrides = []): PurchaseRequest
    {
        $this->actingAs($requester);

        return CreatePurchaseRequestAction::run($requester, array_merge([
            'title' => 'Permission draft',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ], $overrides));
    }

    public function test_requester_can_view_own_without_company_wide_view(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $pr = $this->createDraftFor($requester);

        $this->actingAs($requester);
        $this->assertTrue($requester->can('view', $pr));

        $this->actingAs($requester)
            ->get(route('purchase-requests.show', $pr))
            ->assertOk();
    }

    public function test_viewer_can_view_another_users_same_company_request(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $viewer = User::factory()->forCompany($company)->viewPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $pr = $this->createDraftFor($requester, [
            'company_id' => $company->id,
            'title' => 'Peer draft',
        ]);

        $this->actingAs($viewer);
        $this->assertTrue($viewer->can('view', $pr->fresh()));

        $this->actingAs($viewer)
            ->get(route('purchase-requests.show', $pr))
            ->assertOk();
    }

    public function test_create_only_user_cannot_view_another_users_request(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $createOnly = User::factory()->forCompany($company)->createPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $pr = $this->createDraftFor($requester, [
            'company_id' => $company->id,
            'title' => 'Private draft',
        ]);

        $this->actingAs($createOnly);
        $this->assertFalse($createOnly->can('view', $pr->fresh()));

        $this->actingAs($createOnly)
            ->get(route('purchase-requests.show', $pr))
            ->assertForbidden();
    }

    public function test_index_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('purchase-requests.index'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_open_index(): void
    {
        $this->actingAs(User::factory()->createPurchaseRequests()->create())
            ->get(route('purchase-requests.index'))
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_show_edit_submit_decide_or_destroy(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $outsider = User::factory()->create();
        $pr = $this->createDraftFor($requester);

        $this->actingAs($outsider)
            ->get(route('purchase-requests.show', $pr))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('purchase-requests.edit', $pr))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->post(route('purchase-requests.submit', $pr))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->post(route('purchase-requests.decide', $pr), [
                'decision' => 'approved',
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->delete(route('purchase-requests.destroy', $pr))
            ->assertForbidden();

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'deleted_at' => null,
        ]);
    }

    public function test_admin_and_superuser_cannot_edit_or_delete_non_draft(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $pr = $this->createDraftFor($requester);
        SubmitPurchaseRequestAction::run($pr);
        $pr->refresh();

        $this->assertSame(PurchaseRequestStatus::Submitted, $pr->status);

        foreach ([
            User::factory()->admin()->create(),
            User::factory()->superuser()->create(),
        ] as $privileged) {
            $this->actingAs($privileged);
            $this->assertFalse($privileged->can('update', $pr));
            $this->assertFalse($privileged->can('delete', $pr));
            $this->assertFalse($privileged->can('submit', $pr));

            $this->actingAs($privileged)
                ->get(route('purchase-requests.edit', $pr))
                ->assertForbidden();

            $this->actingAs($privileged)
                ->delete(route('purchase-requests.destroy', $pr))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'deleted_at' => null,
            'status' => PurchaseRequestStatus::Submitted->value,
        ]);
    }

    public function test_direct_view_denial_overrides_group_grant(): void
    {
        $company = Company::factory()->create();
        $group = Group::factory()->create([
            'permissions' => json_encode(['purchase_requests.view' => '1']),
        ]);

        $actor = User::factory()->forCompany($company)->denyViewPurchaseRequests()->create();
        $actor->groups()->attach($group->id);
        $actor->load('groups');

        $requester = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $this->settings->enableMultipleFullCompanySupport();
        $pr = $this->createDraftFor($requester, [
            'company_id' => $company->id,
            'title' => 'Denied view',
        ]);

        $this->actingAs($actor);
        $this->assertFalse($actor->can('view', $pr->fresh()));
        $this->assertFalse($actor->can('index', PurchaseRequest::class));
    }

    public function test_direct_create_denial_overrides_group_grant(): void
    {
        $group = Group::factory()->create([
            'permissions' => json_encode(['purchase_requests.create' => '1']),
        ]);

        $actor = User::factory()->denyCreatePurchaseRequests()->create();
        $actor->groups()->attach($group->id);
        $actor->load('groups');

        $this->actingAs($actor);
        $this->assertFalse($actor->can('create', PurchaseRequest::class));

        $this->actingAs($actor)
            ->post(route('purchase-requests.store'), [
                'title' => 'Denied create',
                'purpose' => 'Purpose',
                'items' => $this->sampleItems(),
            ])
            ->assertForbidden();
    }

    public function test_direct_edit_denial_overrides_group_grant_for_non_owner(): void
    {
        $company = Company::factory()->create();
        $group = Group::factory()->create([
            'permissions' => json_encode(['purchase_requests.edit' => '1']),
        ]);

        $actor = User::factory()->forCompany($company)->denyEditPurchaseRequests()->create();
        $actor->groups()->attach($group->id);
        $actor->load('groups');

        $requester = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $this->settings->enableMultipleFullCompanySupport();
        $pr = $this->createDraftFor($requester, [
            'company_id' => $company->id,
            'title' => 'Denied edit',
        ]);

        $this->actingAs($actor);
        $this->assertFalse($actor->can('update', $pr->fresh()));

        $this->actingAs($actor)
            ->get(route('purchase-requests.edit', $pr))
            ->assertForbidden();
    }

    public function test_deny_view_factory_helper_blocks_view(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $denied = User::factory()->forCompany($company)->denyViewPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $pr = $this->createDraftFor($requester, [
            'company_id' => $company->id,
        ]);

        $this->actingAs($denied);
        $this->assertFalse($denied->can('view', $pr->fresh()));
    }
}
