<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FMDQ staff (Admin, CSD) get an access level like TSP users: Inputters raise staff account
 * changes, Authorisers approve them. A change request now carries the requester's note
 * (required when disabling a user) for the Authoriser to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_change_requests', function (Blueprint $table) {
            $table->text('note')->nullable()->after('requested_by');
        });

        // Existing staff: admin2@fmdqgroup.com authorises, everyone else inputs.
        DB::table('users')->whereIn('type', ['admin', 'csd'])->whereNull('access_level')
            ->update(['access_level' => 'inputter']);
        DB::table('users')->where('email', 'admin2@fmdqgroup.com')->update(['access_level' => 'authoriser']);
    }

    public function down(): void
    {
        Schema::table('user_change_requests', function (Blueprint $table) {
            $table->dropColumn('note');
        });
        DB::table('users')->whereIn('type', ['admin', 'csd'])->update(['access_level' => null]);
    }
};
