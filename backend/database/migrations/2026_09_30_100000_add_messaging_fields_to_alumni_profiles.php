<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 2 needs per-channel opt-out (an alumnus can stop SMS but keep WhatsApp), an unguessable
     * token so a "stop messaging me" link works without signing in, and the timestamps that
     * survey completion and the teaching-assistant flag write.
     */
    public function up(): void
    {
        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->timestamp('sms_opt_out_at')->nullable()->after('consented_at');
            $table->timestamp('whatsapp_opt_out_at')->nullable()->after('sms_opt_out_at');
            $table->string('unsubscribe_token', 32)->nullable()->unique()->after('whatsapp_opt_out_at');
            $table->timestamp('last_survey_completed_at')->nullable()->after('profile_updated_at');
            // Set when a strong graduate says they are available (FR-3); cleared if that stops being true.
            $table->timestamp('ta_flagged_at')->nullable()->after('last_survey_completed_at');
        });

        // Existing rows get their token now; new rows get one when created (see AlumniProfile).
        DB::table('alumni_profiles')->whereNull('unsubscribe_token')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('alumni_profiles')->where('id', $row->id)->update(['unsubscribe_token' => Str::random(24)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->dropUnique(['unsubscribe_token']);
            $table->dropColumn(['sms_opt_out_at', 'whatsapp_opt_out_at', 'unsubscribe_token', 'last_survey_completed_at', 'ta_flagged_at']);
        });
    }
};
