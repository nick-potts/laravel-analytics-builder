<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // SingleStore requires unique keys to contain all shard key columns
            $isSingleStore = Schema::getConnection()->getDriverName() === 'singlestore';
            if ($isSingleStore) {
                $table->string('sku');
            } else {
                $table->string('sku')->unique();
            }

            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
