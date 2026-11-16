<?php

namespace Tests\Feature\PurchaseRequests\Api;

use App\Actions\PurchaseRequests\CreatePurchaseRequestAction;
use App\Actions\PurchaseRequests\SubmitPurchaseRequestAction;
use App\Models\Company;
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
            'title' => 'API permission draft',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ], $overrides));
    }

    public function test_index_requires_permission(): void
    {
        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.purchase-requests.index'))
            ->assertForbidden();
    }

    public function test_create_only_user_cannot_list_another_users_drafts(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $createOnly = User::factory()->forCompany($company)->createPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();

        $other = $this->createDraftFor($requester, [
            'company_id' => $company->id,
            'title' => 'Other draft',
        ]);
        $own = $this->createDraftFor($createOnly, [
            'company_id' => $company->id,
            'title' => 'Mine draft',
        ]);

        $this->actingAs($createOnly);
        $this->assertTrue($createOnly->can('view', $own));
        $this->assertFalse($createOnly->can('view', $other->fresh()));

        $this->actingAsForApi($createOnly)
            ->getJson(route('api.purchase-requests.index'))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Mine draft'])
            ->assertJsonMissing(['title' => 'Other draft']);
    }

    public function test_viewer_can_list_same_company_requests(): void
    {
        $company = Company::factory()->create();
        $requester = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $viewer = User::factory()->forCompany($company)->viewPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();

        $this->createDraftFor($requester, [
            'company_id' => $company->id,
            'title' => 'Company draft',
        ]);

        $this->actingAsForApi($viewer)
            ->getJson(route('api.purchase-requests.index'))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Company draft']);
    }

    public function test_api_index_blocks_cross_company_requests(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $requester = User::factory()->forCompany($companyA)->createPurchaseRequests()->create();
        $viewerB = User::factory()->forCompany($companyB)->viewPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();

        $this->createDraftFor($requester, [
            'company_id' => $companyA->id,
            'title' => 'Company A only',
        ]);

        $response = $this->actingAsForApi($viewerB)
            ->getJson(route('api.purchase-requests.index'))
            ->assertOk();

        $this->assertStringNotContainsString(
            'Company A only',
            $response->getContent()
        );
    }

    public function test_mine_status_pending_approval_and_search_filters(): void
    {
        $company = Company::factory()->create();
        $viewer = User::factory()
            ->forCompany($company)
            ->viewPurchaseRequests()
            ->approvePurchaseRequests()
            ->create();
        $peer = User::factory()->forCompany($company)->createPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();

        $this->createDraftFor($viewer, [
            'company_id' => $company->id,
            'title' => 'Viewer own draft',
        ]);
        $this->createDraftFor($peer, [
            'company_id' => $company->id,
            'title' => 'Peer draft filter',
        ]);
        $pending = $this->createDraftFor($peer, [
            'company_id' => $company->id,
            'title' => 'Pending filter',
        ]);
        SubmitPurchaseRequestAction::run($pending);

        // Baseline: company-wide view sees own and peer drafts.
        $this->actingAsForApi($viewer)
            ->getJson(route('api.purchase-requests.index'))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Viewer own draft'])
            ->assertJsonFragment(['title' => 'Peer draft filter']);

        // mine=1 must exclude peers the viewer could otherwise see.
        $this->actingAsForApi($viewer)
            ->getJson(route('api.purchase-requests.index', ['mine' => 1]))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Viewer own draft'])
            ->assertJsonMissing(['title' => 'Peer draft filter']);

        $this->actingAsForApi($viewer)
            ->getJson(route('api.purchase-requests.index', ['status' => 'draft']))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Viewer own draft'])
            ->assertJsonFragment(['title' => 'Peer draft filter'])
            ->assertJsonMissing(['title' => 'Pending filter']);

        $this->actingAsForApi($viewer)
            ->getJson(route('api.purchase-requests.index', ['pending_approval' => 1]))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Pending filter'])
            ->assertJsonMissing(['title' => 'Viewer own draft']);

        $this->actingAsForApi($viewer)
            ->getJson(route('api.purchase-requests.index', ['search' => 'Pending filter']))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Pending filter'])
            ->assertJsonMissing(['title' => 'Viewer own draft'])
            ->assertJsonMissing(['title' => 'Peer draft filter']);
    }
}
