<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('check_in_out', 255)->nullable()->after('booking_source');
            $table->decimal('client_cost', 19, 4)->nullable()->after('provider_cost');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['check_in_out', 'client_cost']);
        });
    }
};
