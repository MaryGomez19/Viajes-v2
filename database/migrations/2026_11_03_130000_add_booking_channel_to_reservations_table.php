<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'booking_channel')) {
                $table->string('booking_channel', 32)->nullable()->after('state');
                $table->index('booking_channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'booking_channel')) {
                $table->dropIndex(['booking_channel']);
                $table->dropColumn('booking_channel');
            }
        });
    }
};
