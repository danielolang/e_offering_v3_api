<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bids can now be paid online with QPay as well as by bank transfer with evidence:
 * the method used, and the reference that marks a bid as awaiting QPay's return.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('payment_status');
            $table->string('payment_gateway_reference')->nullable()->after('payment_reference')->index();
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropIndex(['payment_gateway_reference']);
            $table->dropColumn(['payment_method', 'payment_gateway_reference']);
        });
    }
};
