<?php

namespace Tests\Feature\PurchaseRequests;

use App\Actions\PurchaseRequests\CreatePurchaseRequestAction;
use App\Actions\PurchaseRequests\GeneratePurchaseRequestNumberAction;
use App\Actions\PurchaseRequests\SubmitPurchaseRequestAction;
use App\Actions\PurchaseRequests\UpdatePurchaseRequestAction;
use App\Actions\WorkflowApprovals\RecordWorkflowApprovalAction;
use App\Enums\ApprovalDecision;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\InvalidPurchaseRequestTransition;
use App\Exceptions\SelfApprovalNotAllowed;
use App\Helpers\PurchaseRequestMoney;
use App\Models\Company;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseRequestWorkflowTest extends TestCase
{
    private function sampleItems(array $overrides = []): array
    {
        return [array_merge([
            'description' => 'Laptop',
            'quantity' => 2,
            'estimated_unit_cost' => '100.5000',
            'specifications' => '16GB RAM',
        ], $overrides)];
    }

    public function test_create_stamps_requester_and_generates_number(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Office kit',
            'purpose' => 'Refresh equipment',
            'items' => $this->sampleItems(),
        ]);

        $this->assertSame(PurchaseRequestStatus::Draft, $pr->status);
        $this->assertSame($user->id, $pr->requested_by);
        $this->assertSame('PR-2026-0001', $pr->request_number);
        $this->assertSame('201.0000', PurchaseRequestMoney::normalize($pr->estimated_total));
        $this->assertCount(1, $pr->items);
        Carbon::setTestNow();
    }

    public function test_client_supplied_totals_are_ignored(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Totals test',
            'purpose' => 'Purpose',
            'estimated_total' => '9999.99',
            'items' => [[
                'description' => 'Item',
                'quantity' => 3,
                'estimated_unit_cost' => '10.00',
                'estimated_line_total' => '9999.99',
            ]],
        ]);

        $this->assertSame('30.0000', PurchaseRequestMoney::normalize($pr->estimated_total));
        $this->assertSame('30.0000', PurchaseRequestMoney::normalize($pr->items->first()->estimated_line_total));
    }

    public function test_client_supplied_currency_is_ignored_and_settings_currency_is_stored(): void
    {
        $this->settings->set(['default_currency' => 'EUR']);
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Currency test',
            'purpose' => 'Purpose',
            'currency' => 'USD',
            'items' => $this->sampleItems(),
        ]);

        $this->assertSame('EUR', $pr->currency);
        $this->assertSame('EUR', Setting::getSettings()->default_currency);
        $this->assertSame('201.0000', PurchaseRequestMoney::normalize($pr->estimated_total));
    }

    public function test_draft_update_cannot_change_currency(): void
    {
        $this->settings->set(['default_currency' => 'GBP']);
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Keep currency',
            'purpose' => 'Purpose',
            'currency' => 'JPY',
            'items' => $this->sampleItems(),
        ]);

        $this->assertSame('GBP', $pr->currency);

        $updated = UpdatePurchaseRequestAction::run($pr, [
            'title' => 'Keep currency',
            'purpose' => 'Purpose',
            'currency' => 'USD',
            'items' => $this->sampleItems(['quantity' => 1, 'estimated_unit_cost' => '50.00']),
        ]);

        $this->assertSame('GBP', $updated->currency);
        $this->assertSame('50.0000', PurchaseRequestMoney::normalize($updated->estimated_total));
    }

    public function test_department_matching_company_is_accepted(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $department = Department::factory()->for($companyA)->create();
        $user = User::factory()->forCompany($companyA)->createPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Dept match',
            'purpose' => 'Purpose',
            'company_id' => $companyA->id,
            'department_id' => $department->id,
            'items' => $this->sampleItems(),
        ]);

        $this->assertSame($department->id, $pr->department_id);
        $this->assertSame($companyA->id, $pr->company_id);
        $this->assertNotSame($companyB->id, $pr->company_id);
    }

    public function test_department_from_other_company_is_rejected(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $foreignDepartment = Department::factory()->for($companyB)->create();
        $user = User::factory()->forCompany($companyA)->createPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAs($user);

        $this->expectException(ValidationException::class);
        CreatePurchaseRequestAction::run($user, [
            'title' => 'Cross dept',
            'purpose' => 'Purpose',
            'company_id' => $companyA->id,
            'department_id' => $foreignDepartment->id,
            'items' => $this->sampleItems(),
        ]);
    }

    public function test_omitted_department_is_accepted(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'No dept',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->assertNull($pr->department_id);
    }

    public function test_invalid_department_id_is_rejected(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $this->expectException(ValidationException::class);
        CreatePurchaseRequestAction::run($user, [
            'title' => 'Bad dept',
            'purpose' => 'Purpose',
            'department_id' => 999999,
            'items' => $this->sampleItems(),
        ]);
    }

    public function test_draft_update_rejects_cross_company_department(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $departmentA = Department::factory()->for($companyA)->create();
        $departmentB = Department::factory()->for($companyB)->create();
        $user = User::factory()->forCompany($companyA)->createPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Update dept',
            'purpose' => 'Purpose',
            'company_id' => $companyA->id,
            'department_id' => $departmentA->id,
            'items' => $this->sampleItems(),
        ]);

        $this->expectException(ValidationException::class);
        UpdatePurchaseRequestAction::run($pr, [
            'title' => 'Update dept',
            'purpose' => 'Purpose',
            'department_id' => $departmentB->id,
            'items' => $this->sampleItems(),
        ]);
    }

    public function test_negative_cost_and_invalid_quantity_rejected(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $this->expectException(ValidationException::class);
        CreatePurchaseRequestAction::run($user, [
            'title' => 'Bad',
            'purpose' => 'Purpose',
            'items' => [[
                'description' => 'Bad item',
                'quantity' => 0,
                'estimated_unit_cost' => '-1',
            ]],
        ]);
    }

    public function test_submit_requires_purpose_and_items_and_locks_edit(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Needs purpose',
            'purpose' => '',
            'items' => $this->sampleItems(),
        ]);

        try {
            SubmitPurchaseRequestAction::run($pr);
            $this->fail('Expected validation failure for purpose.');
        } catch (ValidationException) {
            // expected
        }

        $pr->purpose = 'Valid purpose';
        $pr->save();
        SubmitPurchaseRequestAction::run($pr->fresh());

        $this->assertSame(PurchaseRequestStatus::Submitted, $pr->fresh()->status);
        $this->assertNotNull($pr->fresh()->submitted_at);

        $this->expectException(InvalidPurchaseRequestTransition::class);
        UpdatePurchaseRequestAction::run($pr->fresh(), [
            'title' => 'Changed',
            'purpose' => 'Changed',
            'items' => $this->sampleItems(),
        ]);
    }

    public function test_approval_and_rejection_use_task3_foundation(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $approver = User::factory()->approvePurchaseRequests()->viewPurchaseRequests()->create();
        $this->actingAs($requester);

        $pr = CreatePurchaseRequestAction::run($requester, [
            'title' => 'Approve me',
            'purpose' => 'Need it',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        $this->actingAs($approver);
        RecordWorkflowApprovalAction::run($pr->fresh(), ApprovalDecision::Approved, 'OK');

        $pr->refresh();
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->status);
        $this->assertCount(1, $pr->workflowApprovals);
        $this->assertSame($approver->id, $pr->workflowApprovals->first()->decided_by);

        $this->expectException(InvalidPurchaseRequestTransition::class);
        UpdatePurchaseRequestAction::run($pr, [
            'title' => 'Nope',
            'purpose' => 'Nope',
            'items' => $this->sampleItems(),
        ]);
    }

    public function test_self_approval_denied_for_admin_and_superuser(): void
    {
        $admin = User::factory()->admin()->createPurchaseRequests()->create();
        $this->actingAs($admin);

        $pr = CreatePurchaseRequestAction::run($admin, [
            'title' => 'Own',
            'purpose' => 'Own purpose',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        $this->expectException(SelfApprovalNotAllowed::class);
        RecordWorkflowApprovalAction::run($pr->fresh(), ApprovalDecision::Approved);
    }

    public function test_superuser_cannot_self_approve(): void
    {
        $super = User::factory()->superuser()->create();
        $this->actingAs($super);

        $pr = CreatePurchaseRequestAction::run($super, [
            'title' => 'Own super',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        $this->expectException(SelfApprovalNotAllowed::class);
        RecordWorkflowApprovalAction::run($pr->fresh(), ApprovalDecision::Rejected);
    }

    public function test_requester_can_view_own_without_view_permission(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($requester);

        $pr = CreatePurchaseRequestAction::run($requester, [
            'title' => 'Mine',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->assertTrue($requester->can('view', $pr));
    }

    public function test_direct_user_deny_blocks_approval_permission(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $denied = User::factory()->denyApprovePurchaseRequests()->create();
        $this->actingAs($requester);

        $pr = CreatePurchaseRequestAction::run($requester, [
            'title' => 'Denied approve',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        $this->actingAs($denied);
        $this->assertFalse($denied->can('approve', $pr->fresh()));
    }

    public function test_fmcs_blocks_cross_company_view(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $requester = User::factory()->forCompany($companyA)->createPurchaseRequests()->create();
        $outsider = User::factory()->forCompany($companyB)->viewPurchaseRequests()->create();

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAs($requester);

        $pr = CreatePurchaseRequestAction::run($requester, [
            'title' => 'Company A',
            'purpose' => 'Purpose',
            'company_id' => $companyA->id,
            'items' => $this->sampleItems(),
        ]);

        $this->actingAs($outsider);
        $this->assertFalse($outsider->can('view', $pr->fresh()));
    }

    public function test_request_numbers_restart_each_year(): void
    {
        Carbon::setTestNow('2026-12-31 12:00:00');
        $first = GeneratePurchaseRequestNumberAction::run();
        $this->assertSame('PR-2026-0001', $first);

        Carbon::setTestNow('2027-01-01 12:00:00');
        $second = GeneratePurchaseRequestNumberAction::run();
        $this->assertSame('PR-2027-0001', $second);
        Carbon::setTestNow();
    }

    public function test_failed_create_rolls_back_items(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $beforeRequests = PurchaseRequest::query()->count();
        $beforeItems = PurchaseRequestItem::query()->count();

        try {
            DB::transaction(function () use ($user) {
                CreatePurchaseRequestAction::run($user, [
                    'title' => 'Will fail item',
                    'purpose' => 'Purpose',
                    'items' => $this->sampleItems(),
                ]);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($beforeRequests, PurchaseRequest::query()->count());
        $this->assertSame($beforeItems, PurchaseRequestItem::query()->count());
    }

    public function test_api_index_respects_authorization_and_filters(): void
    {
        $user = User::factory()->viewPurchaseRequests()->createPurchaseRequests()->create();
        $other = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        CreatePurchaseRequestAction::run($user, [
            'title' => 'Mine visible',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->actingAs($other);
        CreatePurchaseRequestAction::run($other, [
            'title' => 'Other draft',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->actingAsForApi($user)
            ->getJson(route('api.purchase-requests.index', ['mine' => 1]))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Mine visible']);
    }

    public function test_draft_only_delete_enforced(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Delete me',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->assertTrue($user->can('delete', $pr));

        SubmitPurchaseRequestAction::run($pr);
        $this->assertFalse($user->can('delete', $pr->fresh()));
    }

    public function test_rejected_requests_are_terminal(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $this->actingAs($requester);

        $pr = CreatePurchaseRequestAction::run($requester, [
            'title' => 'Reject me',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        $this->actingAs($approver);
        RecordWorkflowApprovalAction::run($pr->fresh(), ApprovalDecision::Rejected, 'No budget');

        $pr->refresh();
        $this->assertSame(PurchaseRequestStatus::Rejected, $pr->status);
        $this->assertFalse($requester->can('update', $pr));
        $this->assertFalse($pr->isEligibleForApprovalDecision());
    }

    public function test_unauthorized_create_is_denied_by_policy(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse($user->can('create', PurchaseRequest::class));
    }

    public function test_approvable_contract_methods(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Contract',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->assertSame($user->id, $pr->approvalRequesterId());
        $this->assertSame('purchase_requests.approve', $pr->approvalAbility());
        $this->assertFalse($pr->isEligibleForApprovalDecision());
        $this->assertTrue($pr->approvalCompanyable()->is($pr));

        SubmitPurchaseRequestAction::run($pr);
        $this->assertTrue($pr->fresh()->isEligibleForApprovalDecision());
    }
}
