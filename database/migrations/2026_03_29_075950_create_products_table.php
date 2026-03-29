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
        Schema::create('products', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('category_id')->comment('分类ID');
            $table->unsignedBigInteger('shipping_template_id')->nullable()->comment('运费模板ID');
            $table->string('title')->comment('商品标题');
            $table->string('subtitle')->nullable()->comment('副标题');
            $table->string('main_image')->comment('主图');
            $table->json('images')->comment('商品图片组');
            $table->text('description')->nullable()->comment('商品详情');
            $table->decimal('base_price', 10, 2)->comment('基础价格');
            $table->unsignedInteger('sales_count')->default(0)->comment('销量');
            $table->unsignedInteger('review_count')->default(0)->comment('评价数');
            $table->tinyInteger('status')->default(0)->comment('0下架 1上架');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->timestamps();
            $table->softDeletes()->comment('软删除');

            $table->index(['category_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
