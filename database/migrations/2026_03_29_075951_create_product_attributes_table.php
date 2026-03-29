<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('product_id')->index()->comment('商品ID');
            $table->string('name', 50)->comment('属性名');
            $table->json('values')->comment('可选值');
            $table->integer('sort_order')->default(0)->comment('排序');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
