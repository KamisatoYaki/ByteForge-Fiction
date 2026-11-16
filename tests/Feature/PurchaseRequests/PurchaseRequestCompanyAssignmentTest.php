<?php

namespace Tests\Feature\PurchaseRequests;

use App\Actions\PurchaseRequests\CreatePurchaseRequestAction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseRequestCompanyAssignmentTest extends TestCase
{
    /**
     * @return list<array{description: string, quantity: int, estimated_unit_cost: string}>
     */
    private function sampleItems(): array
    {
        return [[
            'description' => 'Widget',
            'quantity' => 1,
            'estimated_unit_cost' => '10.0000',
        ]];
    }

    public function test_forged_company_id_is_rejected(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $user = User::factory()->forCompany($companyA)->createPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $this->settings->disableFloaterMode();
        $this->actingAs($user);

        $this->expectException(ValidationException::class);

        CreatePurchaseRequestAction::run($user, [
            'title' => 'Forged company',
            'purpose' => 'Purpose',
            'company_id' => $companyB->id,
            'items' => $this->sampleItems(),
        ]);
    }

    public function test_multi_company_user_must_select_company(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $user = User::factory()
            ->forCompany($companyA)
            ->forCompany($companyB)
            ->createPurchaseRequests()
            ->create();

        $this->settings->enableMultipleFullCompanySupport();
        $this->settings->disableFloaterMode();
        $this->actingAs($user);

        try {
            CreatePurchaseRequestAction::run($user, [
                'title' => 'Ambiguous company',
                'purpose' => 'Purpose',
                'items' => $this->sampleItems(),
            ]);
            $this->fail('Expected ValidationException when company selection is missing');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('company_id', $e->errors());
        }
    }

    public function test_valid_company_selection_succeeds(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $user = User::factory()
            ->forCompany($companyA)
            ->forCompany($companyB)
            ->createPurchaseRequests()
            ->create();

        $this->settings->enableMultipleFullCompanySupport();
        $this->settings->disableFloaterMode();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Valid company',
            'purpose' => 'Purpose',
            'company_id' => $companyA->id,
            'items' => $this->sampleItems(),
        ]);

        $this->assertSame($companyA->id, (int) $pr->company_id);
        $this->assertNotSame($companyB->id, (int) $pr->company_id);
    }
}
