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
        Schema::table('cashfree_orders', function (Blueprint $table) {
            $table->json('order_data')->nullable()->after('cashfree_cf_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashfree_orders', function (Blueprint $table) {
            //
        });
    }
};
