<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only.
 *  - users.must_change_password: staff given a temporary password must choose their own at first sign-in.
 *  - students.user_id: continuing students can now have their own account (events, internships, mentoring).
 *  - students.mentor_requested_at / mentor_request_note: a student asks for a mentor from the app.
 *  - the "student" role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
            $table->timestamp('mentor_requested_at')->nullable()->after('contact_shared_at');
            $table->string('mentor_request_note', 500)->nullable()->after('mentor_requested_at');
        });

        DB::table('roles')->updateOrInsert(['slug' => 'student'], ['name' => 'Student', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['mentor_requested_at', 'mentor_request_note']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        DB::table('roles')->where('slug', 'student')->delete();
    }
};
