<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('client_cost', 19, 4)->nullable()->after('provider_cost');
            $table->decimal('supplier_payment', 19, 4)->nullable()->after('client_cost');
            $table->decimal('bonus_discount', 19, 4)->nullable()->after('supplier_payment');
            $table->string('bonus_discount_note', 500)->nullable()->after('bonus_discount');
        });

        Schema::table('flights', function (Blueprint $table) {
            $table->decimal('supplier_payment', 19, 4)->nullable()->after('client_cost');
            $table->decimal('bonus_discount', 19, 4)->nullable()->after('supplier_payment');
            $table->string('bonus_discount_note', 500)->nullable()->after('bonus_discount');
        });

        Schema::table('transports', function (Blueprint $table) {
            $table->decimal('supplier_payment', 19, 4)->nullable()->after('client_cost');
            $table->decimal('bonus_discount', 19, 4)->nullable()->after('supplier_payment');
            $table->string('bonus_discount_note', 500)->nullable()->after('bonus_discount');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'client_cost',
                'supplier_payment',
                'bonus_discount',
                'bonus_discount_note',
            ]);
        });

        Schema::table('flights', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_payment',
                'bonus_discount',
                'bonus_discount_note',
            ]);
        });

        Schema::table('transports', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_payment',
                'bonus_discount',
                'bonus_discount_note',
            ]);
        });
    }
};
