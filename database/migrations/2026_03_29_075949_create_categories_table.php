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
        Schema::create('categories', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('parent_id')->default(0)->index()->comment('父分类ID');
            $table->string('name', 50)->comment('分类名');
            $table->string('icon')->nullable()->comment('分类图标');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->tinyInteger('is_enabled')->default(1)->comment('是否启用');
            $table->tinyInteger('level')->default(1)->comment('层级 1/2/3');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
