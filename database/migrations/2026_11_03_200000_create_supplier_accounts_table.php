<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')
                ->unique()
                ->constrained('reservations')
                ->onDelete('cascade');
            $table->decimal('total_amount', 19, 4)->unsigned()->default(0);
            $table->enum('state', ['pending', 'partial', 'paid', 'canceled'])
                ->default('pending');
            $table->timestamps();

            $table->index('state');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')
                ->constrained('supplier_accounts')
                ->onDelete('cascade');
            $table->decimal('amount', 19, 4)->unsigned();
            $table->dateTime('paid_at');
            $table->enum('payment_method', ['cash', 'transfer', 'card', 'check', 'other'])
                ->default('transfer');
            $table->string('confirmation', 45)->nullable();
            $table->string('observations', 500)->nullable();
            $table->timestamps();

            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_accounts');
    }
};
