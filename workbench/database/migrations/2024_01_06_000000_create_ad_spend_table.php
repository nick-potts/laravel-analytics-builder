<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_spend', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('channel');
            $table->decimal('spend', 12, 2);
            $table->integer('impressions');
            $table->integer('clicks');
            $table->integer('conversions')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend');
    }
};
