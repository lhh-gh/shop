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
        Schema::create('product_skus', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('product_id')->index()->comment('商品ID');
            $table->string('title')->comment('SKU 名称');
            $table->json('attributes')->comment('规格属性');
            $table->decimal('price', 10, 2)->comment('SKU 价格');
            $table->decimal('original_price', 10, 2)->nullable()->comment('原价/划线价');
            $table->unsignedInteger('stock')->default(0)->comment('库存');
            $table->decimal('weight', 8, 2)->nullable()->comment('重量(kg)');
            $table->string('sku_code', 50)->unique()->nullable()->comment('SKU 编码');
            $table->string('image')->nullable()->comment('SKU 图片');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_skus');
    }
};
