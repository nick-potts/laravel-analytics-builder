<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

/**
 * @template TModel of \Workbench\App\Models\Order
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<TModel>
 */
class OrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>
     */
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 500);
        $tax = $subtotal * 0.1;
        $shipping = fake()->randomFloat(2, 5, 20);
        $total = $subtotal + $tax + $shipping;

        return [
            'customer_id' => Customer::factory(),
            'status' => fake()->randomElement(['pending', 'processing', 'completed', 'cancelled']),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping' => $shipping,
            'total' => $total,
        ];
    }

    /**
     * Indicate that the order is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Set a specific total for the order.
     */
    public function withTotal(float $total): static
    {
        return $this->state(function (array $attributes) use ($total) {
            $subtotal = $total / 1.15; // Reverse calculate assuming 10% tax + shipping
            $tax = $subtotal * 0.1;
            $shipping = $total - $subtotal - $tax;

            return [
                'subtotal' => round($subtotal, 2),
                'tax' => round($tax, 2),
                'shipping' => round($shipping, 2),
                'total' => $total,
            ];
        });
    }
}
