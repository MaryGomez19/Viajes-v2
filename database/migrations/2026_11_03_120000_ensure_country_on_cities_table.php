<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities')) {
            return;
        }

        if (! Schema::hasColumn('cities', 'country')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->string('country')->default('México')->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cities') && Schema::hasColumn('cities', 'country')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->dropColumn('country');
            });
        }
    }
};
