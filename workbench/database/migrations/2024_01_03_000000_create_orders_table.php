<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // SingleStore doesn't support foreign keys
            $isSingleStore = Schema::getConnection()->getDriverName() === 'singlestore';
            if ($isSingleStore) {
                $table->unsignedBigInteger('customer_id');
            } else {
                $table->foreignId('customer_id')->constrained('customers');
            }

            $table->string('status')->default('pending');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax', 12, 2);
            $table->decimal('shipping', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
