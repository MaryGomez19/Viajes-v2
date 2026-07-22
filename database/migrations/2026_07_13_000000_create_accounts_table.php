<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')
                ->unique()
                ->constrained('reservations')
                ->onDelete('cascade');
            $table->decimal('total_amount', 19, 4)->unsigned();
            $table->enum('state', ['pending', 'partial', 'paid', 'overdue', 'canceled'])
                ->default('pending');
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
