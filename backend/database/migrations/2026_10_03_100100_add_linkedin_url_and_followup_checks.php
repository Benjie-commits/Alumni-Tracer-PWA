<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An address the alumnus chose to give us (spec section 7.4): where staff look if we cannot reach them.
        // Nothing ever reads their LinkedIn account; it is a link a person opens by hand.
        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->string('linkedin_url', 255)->nullable()->after('city');
        });

        // Staff look for non-responsive alumni by hand. Each look is recorded so nobody is searched for
        // twice in a row, and so it is clear who looked, when, and what they found.
        Schema::create('followup_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome', 16);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['alumni_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('followup_checks');

        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->dropColumn('linkedin_url');
        });
    }
};
