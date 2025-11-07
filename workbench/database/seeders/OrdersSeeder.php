<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\AdSpend;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Product;

class OrdersSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('order_items')->truncate();
        DB::table('orders')->truncate();
        DB::table('products')->truncate();
        DB::table('customers')->truncate();
        DB::table('ad_spend')->truncate();

        $segments = ['enterprise', 'smb', 'consumer'];
        $customers = collect(range(1, 15))->map(function ($i) use ($segments) {
            return Customer::create([
                'name' => "Customer {$i}",
                'email' => "customer{$i}@example.com",
                'segment' => $segments[array_rand($segments)],
                'created_at' => now()->subDays(rand(30, 365)),
                'updated_at' => now(),
            ]);
        });

        $products = collect([
            ['sku' => 'CHAIR-001', 'name' => 'Ergo Chair', 'price' => 299.99],
            ['sku' => 'DESK-002', 'name' => 'Standing Desk', 'price' => 599.99],
            ['sku' => 'MON-003', 'name' => 'UltraWide Monitor', 'price' => 899.99],
            ['sku' => 'LAMP-004', 'name' => 'Desk Lamp', 'price' => 79.99],
            ['sku' => 'MAT-005', 'name' => 'Anti-fatigue Mat', 'price' => 59.99],
        ])->map(fn ($product) => Product::create($product + [
            'created_at' => now()->subDays(rand(10, 100)),
            'updated_at' => now(),
        ]));

        $statuses = ['pending', 'processing', 'completed', 'cancelled'];

        foreach (range(1, 40) as $i) {
            $customer = $customers->random();
            $orderDate = Carbon::today()->subDays(rand(0, 60));

            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => $statuses[array_rand($statuses)],
                'subtotal' => 0,
                'tax' => 0,
                'shipping' => rand(1000, 2500) / 100,
                'total' => 0,
                'created_at' => $orderDate,
                'updated_at' => $orderDate,
            ]);

            $itemCount = rand(1, 4);
            $subtotal = 0;
            $totalCost = 0;

            foreach (range(1, $itemCount) as $position) {
                $product = $products->random();
                $quantity = rand(1, 3);
                $price = $product->price;
                $cost = $price * 0.6; // assume 40% margin

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'cost' => $cost,
                    'created_at' => $orderDate,
                    'updated_at' => $orderDate,
                ]);

                $subtotal += $price * $quantity;
                $totalCost += $cost * $quantity;
            }

            $tax = $subtotal * 0.1;
            $order->update([
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $subtotal + $tax + $order->shipping,
            ]);

            $order->setAttribute('cost_of_goods', $totalCost);
        }

        // Seed ad spend data for the last 30 days
        $channels = ['search', 'social', 'display'];
        foreach (range(0, 29) as $offset) {
            $date = Carbon::today()->subDays($offset);
            foreach ($channels as $channel) {
                AdSpend::create([
                    'date' => $date,
                    'channel' => $channel,
                    'spend' => rand(5000, 20000) / 100,
                    'impressions' => rand(1_000, 50_000),
                    'clicks' => rand(500, 5_000),
                    'conversions' => rand(20, 150),
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }
}
