<?php

namespace Database\Factories;

use App\Helpers\PurchaseRequestMoney;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequestItem>
 */
class PurchaseRequestItemFactory extends Factory
{
    protected $model = PurchaseRequestItem::class;

    public function definition(): array
    {
        $qty = $this->faker->numberBetween(1, 5);
        $unit = $this->faker->randomFloat(2, 1, 100);

        return [
            'purchase_request_id' => PurchaseRequest::factory(),
            'description' => $this->faker->words(3, true),
            'quantity' => $qty,
            'estimated_unit_cost' => PurchaseRequestMoney::normalize($unit),
            'estimated_line_total' => PurchaseRequestMoney::multiply($qty, $unit),
            'category_id' => null,
            'specifications' => null,
            'sort_order' => 0,
        ];
    }
}
