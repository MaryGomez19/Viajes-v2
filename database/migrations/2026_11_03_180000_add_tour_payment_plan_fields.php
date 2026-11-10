<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_plan_installments')->nullable()->after('state');
            $table->boolean('payment_plan_managed')->default(false)->after('payment_plan_installments');
        });

        Schema::table('scheduled_payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('sequence')->nullable()->after('account_id');
            $table->decimal('amount_settled', 19, 4)->unsigned()->default(0)->after('amount');
            $table->boolean('is_plan_installment')->default(false)->after('observations');
            $table->index(['account_id', 'sequence']);
        });

        DB::statement(
            "ALTER TABLE scheduled_payments MODIFY status ENUM('pending', 'partial', 'paid', 'overdue', 'canceled') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE scheduled_payments MODIFY status ENUM('pending', 'paid', 'overdue', 'canceled') NOT NULL DEFAULT 'pending'"
        );

        Schema::table('scheduled_payments', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'sequence']);
            $table->dropColumn(['sequence', 'amount_settled', 'is_plan_installment']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['payment_plan_installments', 'payment_plan_managed']);
        });
    }
};
