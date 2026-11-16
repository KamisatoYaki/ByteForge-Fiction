<?php

namespace Tests\Feature\PurchaseRequests;

use App\Actions\PurchaseRequests\CreatePurchaseRequestAction;
use App\Actions\PurchaseRequests\SubmitPurchaseRequestAction;
use App\Actions\PurchaseRequests\UpdatePurchaseRequestAction;
use App\Actions\WorkflowApprovals\RecordWorkflowApprovalAction;
use App\Enums\ActionType;
use App\Enums\ActivityItemType;
use App\Enums\ApprovalDecision;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\SelfApprovalNotAllowed;
use App\Models\Actionlog;
use App\Models\Company;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\WorkflowApproval;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

class PurchaseRequestActionlogTest extends TestCase
{
    /**
     * @return list<array{description: string, quantity: int, estimated_unit_cost: string}>
     */
    private function sampleItems(): array
    {
        return [[
            'description' => 'Laptop',
            'quantity' => 2,
            'estimated_unit_cost' => '100.0000',
        ]];
    }

    public function test_create_produces_exactly_one_create_actionlog(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Create log',
            'purpose' => 'Purpose',
            'company_id' => $company->id,
            'items' => $this->sampleItems(),
        ]);

        $logs = Actionlog::query()
            ->where('item_type', PurchaseRequest::class)
            ->where('item_id', $pr->id)
            ->get();

        $this->assertCount(1, $logs);
        $this->assertSame(ActionType::Create->value, $logs->first()->action_type);
        $this->assertSame($user->id, (int) $logs->first()->created_by);
        $this->assertSame($company->id, (int) $logs->first()->company_id);
    }

    public function test_update_produces_update_actionlog(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Before',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        UpdatePurchaseRequestAction::run($pr, [
            'title' => 'After',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->assertDatabaseHas('action_logs', [
            'item_type' => PurchaseRequest::class,
            'item_id' => $pr->id,
            'action_type' => ActionType::Update->value,
            'created_by' => $user->id,
        ]);
    }

    public function test_submit_produces_distinct_submitted_actionlog(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Submit me',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        SubmitPurchaseRequestAction::run($pr);

        $this->assertDatabaseHas('action_logs', [
            'item_type' => PurchaseRequest::class,
            'item_id' => $pr->id,
            'action_type' => ActionType::Submitted->value,
            'created_by' => $user->id,
        ]);

        $types = Actionlog::query()
            ->where('item_type', PurchaseRequest::class)
            ->where('item_id', $pr->id)
            ->orderBy('id')
            ->pluck('action_type')
            ->all();

        $this->assertSame([
            ActionType::Create->value,
            ActionType::Submitted->value,
        ], $types);
    }

    public function test_approve_writes_workflow_approval_and_actionlog(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $this->actingAs($requester);

        $pr = CreatePurchaseRequestAction::run($requester, [
            'title' => 'Approve me',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        $this->actingAs($approver);
        RecordWorkflowApprovalAction::run($pr->fresh(), ApprovalDecision::Approved, 'OK');

        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
        $this->assertSame(1, WorkflowApproval::query()->count());
        $this->assertDatabaseHas('action_logs', [
            'item_type' => PurchaseRequest::class,
            'item_id' => $pr->id,
            'action_type' => ActionType::Approved->value,
            'created_by' => $approver->id,
        ]);
    }

    public function test_reject_writes_workflow_approval_and_actionlog(): void
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

        $this->assertSame(PurchaseRequestStatus::Rejected, $pr->fresh()->status);
        $this->assertSame(1, WorkflowApproval::query()->count());
        $this->assertDatabaseHas('action_logs', [
            'item_type' => PurchaseRequest::class,
            'item_id' => $pr->id,
            'action_type' => ActionType::Rejected->value,
            'created_by' => $approver->id,
        ]);
    }

    public function test_failed_self_approval_does_not_write_actionlog_or_workflow_approval(): void
    {
        $admin = User::factory()->admin()->createPurchaseRequests()->create();
        $this->actingAs($admin);

        $pr = CreatePurchaseRequestAction::run($admin, [
            'title' => 'Self approve',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        try {
            RecordWorkflowApprovalAction::run($pr->fresh(), ApprovalDecision::Approved);
            $this->fail('Expected SelfApprovalNotAllowed');
        } catch (SelfApprovalNotAllowed) {
            // expected
        }

        $this->assertSame(0, WorkflowApproval::query()->count());
        $this->assertSame(
            0,
            Actionlog::query()
                ->where('item_type', PurchaseRequest::class)
                ->where('item_id', $pr->id)
                ->whereIn('action_type', [
                    ActionType::Approved->value,
                    ActionType::Rejected->value,
                ])
                ->count()
        );
    }

    public function test_delete_draft_writes_delete_actionlog(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Delete me',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);

        $this->delete(route('purchase-requests.destroy', $pr))
            ->assertRedirect(route('purchase-requests.index'));

        $this->assertSoftDeleted('purchase_requests', ['id' => $pr->id]);
        $this->assertDatabaseHas('action_logs', [
            'item_type' => PurchaseRequest::class,
            'item_id' => $pr->id,
            'action_type' => ActionType::Delete->value,
            'created_by' => $user->id,
        ]);
    }

    public function test_actionlog_stamps_company_from_purchase_request(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $user = User::factory()->forCompany($companyA)->createPurchaseRequests()->create();
        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAs($user);

        $pr = CreatePurchaseRequestAction::run($user, [
            'title' => 'Company stamp',
            'purpose' => 'Purpose',
            'company_id' => $companyA->id,
            'items' => $this->sampleItems(),
        ]);

        $log = Actionlog::query()
            ->where('item_type', PurchaseRequest::class)
            ->where('item_id', $pr->id)
            ->where('action_type', ActionType::Create->value)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($companyA->id, (int) $log->company_id);
        $this->assertNotSame($companyB->id, (int) $log->company_id);
    }

    public function test_unauthorized_create_does_not_write_actionlog(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse($user->can('create', PurchaseRequest::class));

        $before = Actionlog::query()->where('item_type', PurchaseRequest::class)->count();

        $this->post(route('purchase-requests.store'), [
            'title' => 'Nope',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ])->assertForbidden();

        $this->assertSame(
            $before,
            Actionlog::query()->where('item_type', PurchaseRequest::class)->count()
        );
    }

    public function test_unauthorized_approval_does_not_write_actionlog(): void
    {
        $requester = User::factory()->createPurchaseRequests()->create();
        $outsider = User::factory()->create();
        $this->actingAs($requester);

        $pr = CreatePurchaseRequestAction::run($requester, [
            'title' => 'Needs approve',
            'purpose' => 'Purpose',
            'items' => $this->sampleItems(),
        ]);
        SubmitPurchaseRequestAction::run($pr);

        $this->actingAs($outsider);

        try {
            RecordWorkflowApprovalAction::run($pr->fresh(), ApprovalDecision::Approved);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, WorkflowApproval::query()->count());
        $this->assertSame(
            0,
            Actionlog::query()
                ->where('item_type', PurchaseRequest::class)
                ->where('item_id', $pr->id)
                ->where('action_type', ActionType::Approved->value)
                ->count()
        );
    }

    public function test_activity_item_type_recognizes_purchase_request(): void
    {
        $this->assertSame(
            PurchaseRequest::class,
            ActivityItemType::PurchaseRequest->class()
        );
        $this->assertSame(
            ActivityItemType::PurchaseRequest,
            ActivityItemType::tryFromAny('purchaserequest')
        );
        $this->assertSame(
            ActivityItemType::PurchaseRequest,
            ActivityItemType::tryFromAny(PurchaseRequest::class)
        );
        $this->assertContains(
            PurchaseRequest::class,
            ActivityItemType::classes()
        );
    }

    public function test_failed_actionlog_persistence_rolls_back_create(): void
    {
        $user = User::factory()->createPurchaseRequests()->create();
        $this->actingAs($user);

        $beforeRequests = PurchaseRequest::query()->count();
        $beforeLogs = Actionlog::query()->where('item_type', PurchaseRequest::class)->count();

        // Eloquent cancels save when a saving listener returns false; logaction()
        // then returns false and RecordPurchaseRequestActivityAction must throw.
        $blockNextActionlogSave = true;
        Actionlog::saving(function () use (&$blockNextActionlogSave) {
            if ($blockNextActionlogSave) {
                $blockNextActionlogSave = false;

                return false;
            }
        });

        try {
            CreatePurchaseRequestAction::run($user, [
                'title' => 'Audit failure',
                'purpose' => 'Purpose',
                'items' => $this->sampleItems(),
            ]);
            $this->fail('Expected RuntimeException when Actionlog persistence fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Actionlog', $e->getMessage());
        } finally {
            $blockNextActionlogSave = false;
        }

        $this->assertSame($beforeRequests, PurchaseRequest::query()->count());
        $this->assertSame(
            $beforeLogs,
            Actionlog::query()->where('item_type', PurchaseRequest::class)->count()
        );
    }
}
