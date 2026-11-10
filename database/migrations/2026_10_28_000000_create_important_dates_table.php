<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('important_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('type', 32);
            $table->date('event_date');
            $table->text('observation')->nullable();
            $table->string('reminder_preset', 32)->nullable();
            $table->unsignedSmallInteger('reminder_days_before')->nullable();
            $table->time('reminder_time')->nullable();
            $table->string('google_calendar_event_id')->nullable();
            $table->string('sync_status', 16)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_date']);
            $table->index(['sync_status']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('important_dates');
    }
};
