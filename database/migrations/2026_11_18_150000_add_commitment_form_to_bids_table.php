<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy Standardised Commitment Form's details that have no column of their own:
 * participant names and address, allotment preference, CSCS / FMDQ Depository boxes, extra
 * bank details, the up-to-three orders, and the signature / seal images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->json('commitment')->nullable()->after('payee_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropColumn('commitment');
        });
    }
};
