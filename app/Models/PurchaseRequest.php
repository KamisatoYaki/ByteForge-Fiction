<?php

namespace App\Models;

use App\Actions\PurchaseRequests\RecordPurchaseRequestActivityAction;
use App\Contracts\Approvable;
use App\Enums\ActionType;
use App\Enums\ApprovalDecision;
use App\Enums\PurchaseRequestStatus;
use App\Models\Traits\CompanyableTrait;
use App\Models\Traits\HasWorkflowApprovals;
use App\Models\Traits\Searchable;
use App\Presenters\Presentable;
use App\Presenters\PurchaseRequestPresenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * ByteForge purchase request header. Implements Approvable for Task 3 approval.
 *
 * @property PurchaseRequestStatus $status
 * @mixin IdeHelperPurchaseRequest
 */
class PurchaseRequest extends SnipeModel implements Approvable
{
    use CompanyableTrait;
    use HasFactory;
    use HasWorkflowApprovals;
    use Presentable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $presenter = PurchaseRequestPresenter::class;

    protected $table = 'purchase_requests';

    /**
     * Sensitive lifecycle/identity fields are stamped by Actions, not mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'purpose',
        'department_id',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => PurchaseRequestStatus::class,
        'requested_by' => 'integer',
        'company_id' => 'integer',
        'department_id' => 'integer',
        'estimated_total' => 'decimal:4',
        'submitted_at' => 'datetime',
        'created_by' => 'integer',
    ];

    /**
     * @var array<string, string>
     */
    public $rules = [
        'title' => 'required|string|max:255',
        'purpose' => 'nullable|string|max:65535',
        'department_id' => 'nullable|integer|exists:departments,id',
        'notes' => 'nullable|string|max:65535',
        'currency' => 'nullable|string|max:10',
        'company_id' => 'nullable|integer|exists:companies,id|fmcs_company',
        'requested_by' => 'required|integer|exists:users,id',
        'request_number' => 'required|string|max:32',
        'status' => 'required|string|in:draft,submitted,approved,rejected',
        'estimated_total' => 'required|numeric|gte:0|max:99999999999999999.99',
    ];

    protected $searchableAttributes = ['request_number', 'title', 'purpose', 'notes'];

    protected $searchableRelations = [
        'requester' => ['first_name', 'last_name', 'username', 'email'],
        'department' => ['name'],
        'company' => ['name'],
    ];

    protected $attributes = [
        'status' => 'draft',
        'estimated_total' => 0,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return HasMany<PurchaseRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function approvalRequesterId(): ?int
    {
        return $this->requested_by;
    }

    public function isEligibleForApprovalDecision(): bool
    {
        return $this->status === PurchaseRequestStatus::Submitted;
    }

    public function approvalAbility(): string
    {
        return 'purchase_requests.approve';
    }

    public function approvalCompanyable(): Model
    {
        return $this;
    }

    public function applyApprovalDecision(ApprovalDecision $decision, User $decider): void
    {
        $this->status = match ($decision) {
            ApprovalDecision::Approved => PurchaseRequestStatus::Approved,
            ApprovalDecision::Rejected => PurchaseRequestStatus::Rejected,
        };
        $this->save();

        // Dual-write into Actionlog inside the surrounding approval transaction.
        // WorkflowApproval remains the authoritative decision history.
        RecordPurchaseRequestActivityAction::run(
            $this,
            $decision === ApprovalDecision::Approved
                ? ActionType::Approved
                : ActionType::Rejected,
            $decider,
            note: $this->request_number,
            meta: [
                'request_number' => $this->request_number,
                'decision' => $decision->value,
            ],
        );
    }

    public function getDisplayNameAttribute(): string
    {
        return (string) ($this->request_number.' — '.$this->title);
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseRequestStatus::Draft;
    }

    public function recalculateEstimatedTotal(): void
    {
        $this->estimated_total = $this->items()->sum('estimated_line_total');
    }
}
