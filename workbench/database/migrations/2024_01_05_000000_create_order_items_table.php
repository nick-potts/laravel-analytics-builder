<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            // SingleStore doesn't support foreign keys
            $isSingleStore = Schema::getConnection()->getDriverName() === 'singlestore';
            if ($isSingleStore) {
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id');
            } else {
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products');
            }

            $table->integer('quantity');
            $table->decimal('price', 12, 2);
            $table->decimal('cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
