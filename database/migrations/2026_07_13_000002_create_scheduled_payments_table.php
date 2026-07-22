<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->onDelete('cascade');
            $table->date('due_date');
            $table->decimal('amount', 19, 4)->unsigned();
            $table->enum('status', ['pending', 'paid', 'overdue', 'canceled'])
                ->default('pending');
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->index('due_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_payments');
    }
};
