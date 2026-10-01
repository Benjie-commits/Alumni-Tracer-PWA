<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TracerSurveyCycle is the survey at one milestone (6 months, 1 year, 3 years). Its questions live
     * in immutable versions so an answer can always be read against the exact wording it was given for,
     * even after the Registrar or QA Directorate revise the questionnaire.
     */
    public function up(): void
    {
        Schema::create('tracer_survey_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('milestone_months')->unique();
            $table->string('title');
            $table->boolean('is_active')->default(true);
            // How long an invitation stays open after the milestone; null uses config('sunates.surveys.window_days').
            $table->unsignedSmallInteger('window_days')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tracer_survey_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracer_survey_cycle_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->string('definition_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tracer_survey_cycle_id', 'version']);
        });

        Schema::table('tracer_survey_cycles', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('tracer_survey_versions')->nullOnDelete();
        });

        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracer_survey_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            // The secret in the link sent to the alumnus; never shown in the staff console.
            $table->string('token', 48)->unique();
            $table->string('status', 16)->default('scheduled');
            $table->timestamp('due_at');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // One invitation per alumnus per milestone, so the scheduler can safely run every day.
            // Explicit name: the generated one exceeds MySQL's 64-character identifier limit.
            $table->unique(['tracer_survey_cycle_id', 'alumni_profile_id'], 'survey_invitations_cycle_profile_unique');
            $table->index(['status', 'expires_at']);
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_invitation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tracer_survey_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            // Chosen by the phone before sending, so a retry after a dropped connection is recognised (idempotent).
            $table->uuid('submission_id')->unique();
            $table->json('answers');
            // Pulled out of the answers for the Phase 3 outcome dashboards.
            $table->string('employment_status', 32)->nullable();
            $table->string('further_study_status', 32)->nullable();
            $table->boolean('ta_interest')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index('employment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_invitations');
        Schema::table('tracer_survey_cycles', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('tracer_survey_versions');
        Schema::dropIfExists('tracer_survey_cycles');
    }
};
