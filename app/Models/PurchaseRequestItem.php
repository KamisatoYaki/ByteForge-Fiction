<?php

namespace App\Models;

use App\Helpers\PurchaseRequestMoney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * Line item on a ByteForge PurchaseRequest.
 *
 * @mixin IdeHelperPurchaseRequestItem
 */
class PurchaseRequestItem extends SnipeModel
{
    use HasFactory;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'purchase_request_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'description',
        'quantity',
        'estimated_unit_cost',
        'category_id',
        'specifications',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'purchase_request_id' => 'integer',
        'quantity' => 'integer',
        'estimated_unit_cost' => 'decimal:4',
        'estimated_line_total' => 'decimal:4',
        'category_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * @var array<string, string>
     */
    public $rules = [
        'purchase_request_id' => 'required|integer|exists:purchase_requests,id',
        'description' => 'required|string|max:255',
        'quantity' => 'required|integer|min:1',
        'estimated_unit_cost' => 'required|numeric|gte:0|max:99999999999999999.99',
        'estimated_line_total' => 'required|numeric|gte:0|max:99999999999999999.99',
        'category_id' => 'nullable|integer|exists:categories,id',
        'specifications' => 'nullable|string|max:65535',
        'sort_order' => 'nullable|integer|min:0',
    ];

    /**
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function recalculateLineTotal(): void
    {
        $this->estimated_line_total = PurchaseRequestMoney::multiply(
            (int) $this->quantity,
            $this->estimated_unit_cost ?? 0
        );
    }
}
