<?php

namespace Database\Factories\Modules\POS\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;

class PosOrderItemFactory extends Factory
{
    protected $model = PosOrderItem::class;

    public function definition(): array
    {
        return [
            'pos_order_id' => PosOrder::factory(),
            'product_id' => Product::factory(),
            'name' => $this->faker->word,
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 10.00,
            'tax_amount' => 10.00,
            'line_total' => 110.00,
        ];
    }
}
