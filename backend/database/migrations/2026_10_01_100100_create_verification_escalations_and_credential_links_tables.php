<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a lookup finds nobody (or cannot tell two people apart) the requester can ask the
        // Registrar's office to check by hand. These are Registrar work items, so they live in the main database.
        Schema::create('verification_escalations', function (Blueprint $table) {
            $table->id();
            $table->string('request_reference', 16)->unique();
            $table->string('organisation');
            $table->string('requester_name');
            $table->string('requester_email');
            $table->string('requester_phone', 32)->nullable();
            // What they were asking about, copied from the lookup so the Registrar needs nothing else.
            $table->string('subject_name');
            $table->unsignedSmallInteger('subject_graduation_year')->nullable();
            $table->string('subject_programme')->nullable();
            $table->string('lookup_result', 16);
            $table->text('message')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // "Alumni ... request a verified credential link" (spec section 2.3): a private link an
        // alumnus can give an employer, which shows them verified without any searching.
        Schema::create('credential_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->index(['alumni_profile_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_links');
        Schema::dropIfExists('verification_escalations');
    }
};
