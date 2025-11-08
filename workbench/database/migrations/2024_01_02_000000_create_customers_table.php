<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // SingleStore requires unique keys to contain all shard key columns
            $isSingleStore = Schema::getConnection()->getDriverName() === 'singlestore';
            if ($isSingleStore) {
                $table->string('email');
            } else {
                $table->string('email')->unique();
            }

            $table->string('segment')->default('general');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
