<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every CAC registry search for a QII / Non-QII institution: the one made when the request was
 * submitted and each "Fetch Latest from CAC" by an admin, with the time and the full response,
 * so the reviewer sees what CAC said and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cac_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('rc_number', 60);
            $table->string('name');
            // verified | name_mismatch | rc_mismatch | not_found | unavailable
            $table->string('result', 20);
            // The CAC entry whose RC number matched, if any.
            $table->json('company')->nullable();
            // Every entry CAC returned for the search.
            $table->json('response')->nullable();
            $table->string('error')->nullable();
            // Null when the applicant's own submission triggered the search.
            $table->foreignId('searched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('searched_at');
            $table->timestamps();

            $table->index(['institution_id', 'searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cac_lookups');
    }
};
