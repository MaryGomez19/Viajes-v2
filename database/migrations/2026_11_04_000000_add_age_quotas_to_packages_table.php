<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('quota_adults')->nullable()->after('stock');
            $table->unsignedInteger('quota_juniors')->nullable()->after('quota_adults');
            $table->unsignedInteger('quota_children')->nullable()->after('quota_juniors');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['quota_adults', 'quota_juniors', 'quota_children']);
        });
    }
};
