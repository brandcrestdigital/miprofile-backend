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
        Schema::table('affiliate_withdrawals', function (Blueprint $table) {
            $table->string('payment_screenshot')->nullable()->after('remark');
            $table->string('transaction_reference')->nullable()->after('payment_screenshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_withdrawals', function (Blueprint $table) {
            $table->dropColumn([
                'payment_screenshot',
                'transaction_reference',

            ]);
        });
    }
};
