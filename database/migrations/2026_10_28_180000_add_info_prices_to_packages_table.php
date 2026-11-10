<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('package_total_cost', 12, 2)->nullable()->after('price_child');
            $table->decimal('info_price_adult', 12, 2)->nullable()->after('package_total_cost');
            $table->decimal('info_price_junior', 12, 2)->nullable()->after('info_price_adult');
            $table->decimal('info_price_child', 12, 2)->nullable()->after('info_price_junior');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'package_total_cost',
                'info_price_adult',
                'info_price_junior',
                'info_price_child',
            ]);
        });
    }
};
