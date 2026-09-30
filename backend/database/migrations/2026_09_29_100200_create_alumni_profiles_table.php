<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * AlumniProfile is the core record (spec section 6). Rows are seeded from the Registrar's
     * spreadsheets with verification_status = 'unclaimed' and no user; an alumnus claims a row when
     * they register and match it (FR-2). Self-declared alumni who match nothing are created as
     * 'pending' for Registrar verification.
     */
    public function up(): void
    {
        Schema::create('alumni_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // Academic history (Registrar-owned)
            $table->string('student_number', 64)->nullable()->unique();
            // What a self-declared alumnus typed when nothing matched; staff review it and link the
            // profile to the real Registrar record. Kept apart from student_number so a claim can
            // never collide with (or reveal) an existing record.
            $table->string('declared_student_number', 64)->nullable()->index();
            $table->foreignId('programme_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->date('graduation_date')->nullable();
            $table->string('class_of_award', 64)->nullable();

            // Identity
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();

            // Contact and location (alumnus-owned)
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp_number', 32)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();

            // Outcomes (feeds Phase 3 dashboards)
            $table->string('employment_status', 32)->nullable();
            $table->string('further_study_status', 32)->nullable();
            $table->string('further_study_institution')->nullable();
            $table->string('further_study_programme')->nullable();

            // Provenance and verification
            $table->string('record_source', 32)->default('registrar_import');
            $table->string('verification_status', 32)->default('unclaimed');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consented_at')->nullable();
            // Last time the alumnus (not staff) touched the record; drives data-freshness nudges (FR-7).
            $table->timestamp('profile_updated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('graduation_year');
            $table->index('verification_status');
        });

        Schema::create('employment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            $table->string('employer');
            $table->string('job_title')->nullable();
            $table->string('sector', 100)->nullable();
            $table->string('employment_type', 32)->default('employed');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->timestamps();

            $table->index(['alumni_profile_id', 'is_current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_records');
        Schema::dropIfExists('alumni_profiles');
    }
};
