<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partial_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->onDelete('cascade');
            $table->decimal('amount', 19, 4)->unsigned();
            $table->enum('transaction_type', ['payment', 'refund'])->default('payment');
            $table->dateTime('paid_at');
            $table->enum('payment_method', ['cash', 'transfer', 'card', 'check', 'other'])
                ->default('transfer');
            $table->string('sent_to_supplier')->nullable();
            $table->string('confirmation', 45)->nullable();
            $table->timestamps();

            $table->index('paid_at');
            $table->index('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partial_payments');
    }
};
