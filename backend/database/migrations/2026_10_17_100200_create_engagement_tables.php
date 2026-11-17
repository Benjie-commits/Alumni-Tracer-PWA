<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-5 engagement (spec section 6: EventPosting, JobPosting, MentorshipMatch): event notices and
     * job/internship postings targeted to alumni segments, and mentorship matching with current students.
     *
     * Students have no accounts: the university has not decided how a current student's identity would be
     * verified, so staff record the students taking part and make each match by hand.
     */
    public function up(): void
    {
        // Who a posting is for. All null = every alumnus. Otherwise a school, department or programme,
        // and/or a range of graduation years; an alumnus sees a posting only if they fit every part set.
        $audience = function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('graduation_year_from')->nullable();
            $table->unsignedSmallInteger('graduation_year_to')->nullable();
        };

        Schema::create('job_postings', function (Blueprint $table) use ($audience) {
            $table->id();
            $table->string('title');
            $table->string('organisation');
            $table->string('type', 16); // job | internship
            $table->string('location')->nullable();
            $table->text('description');
            // Where to apply: a link, an email address, or instructions.
            $table->string('how_to_apply', 500);
            $table->date('closes_on')->nullable();
            $audience($table);
            // Null = draft, visible to staff only.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['published_at', 'closes_on']);
        });

        Schema::create('event_postings', function (Blueprint $table) use ($audience) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('venue')->nullable();
            $table->string('online_url', 500)->nullable();
            $audience($table);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['published_at', 'starts_at']);
        });

        // Current students taking part in mentorship, entered by staff (no student accounts).
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number', 64)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->foreignId('programme_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('year_of_study')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            // The student agreed that their mentor may see their email/phone. Without it a mentor sees name
            // and programme only, and staff make the introduction.
            $table->timestamp('contact_shared_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mentorship_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active'); // active | ended
            $table->string('focus', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['alumni_profile_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        // Alumni choose to be mentors; nobody is matched without saying yes.
        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->timestamp('mentor_opt_in_at')->nullable()->after('ta_flagged_at');
            $table->string('mentor_topics', 255)->nullable()->after('mentor_opt_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->dropColumn(['mentor_opt_in_at', 'mentor_topics']);
        });

        Schema::dropIfExists('mentorship_matches');
        Schema::dropIfExists('students');
        Schema::dropIfExists('event_postings');
        Schema::dropIfExists('job_postings');
    }
};
