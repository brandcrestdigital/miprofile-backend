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
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')
                ->constrained('affiliates')
                ->cascadeOnDelete();

            $table->string('transaction_no')->unique();

            $table->enum('type', ['credit', 'debit']);

            $table->string('description');

            $table->decimal('amount', 12, 2);

            $table->enum('status', [
                'pending',
                'completed',
                'failed',
                'cancelled'
            ])->default('completed');

            $table->nullableMorphs('reference');

            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
