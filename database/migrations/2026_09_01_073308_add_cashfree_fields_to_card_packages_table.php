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
        Schema::table('organization_card_purchases', function (Blueprint $table) {
            $table->string('cashfree_order_id')->nullable()->index();
            $table->string('cashfree_cf_order_id')->nullable()->index();
            $table->string('cashfree_payment_id')->nullable()->index();
            $table->json('cashfree_response')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_card_purchases', function (Blueprint $table) {
            //
        });
    }
};
