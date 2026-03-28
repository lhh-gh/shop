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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->unique()->nullable()->after('id');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('nickname', 50)->nullable()->after('password');
            $table->string('avatar', 255)->nullable()->after('nickname');
            $table->tinyInteger('status')->default(1)->comment('1=active, 0=disabled')->after('avatar');
            $table->timestamp('phone_verified_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'nickname',
                'avatar',
                'status',
                'phone_verified_at'
            ]);
        });
    }
};
