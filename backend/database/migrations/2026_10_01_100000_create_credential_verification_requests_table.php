<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec section 9: "verification-request logs stored separately from full profile data to limit
     * exposure". This table holds no profile data, only who asked, what they typed and what we
     * answered, and it can be moved to its own database: set VERIFICATION_DB_CONNECTION to a second
     * connection in config/database.php. For that reason there are no foreign keys into the main
     * database (a profile or programme id here is only a reference).
     */
    public function getConnection(): ?string
    {
        return config('sunates.verification.connection') ?: null;
    }

    public function up(): void
    {
        Schema::create('credential_verification_requests', function (Blueprint $table) {
            $table->id();
            // Quoted back to the requester so they can cite it ("VER-7K3M9QXD").
            $table->string('reference', 16)->unique();
            $table->string('channel', 16);
            $table->string('organisation')->nullable();
            $table->string('requester_email')->nullable();
            $table->string('query_name')->nullable();
            $table->unsignedBigInteger('query_programme_id')->nullable();
            $table->unsignedSmallInteger('query_graduation_year')->nullable();
            $table->string('result', 16);
            $table->unsignedBigInteger('matched_profile_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_verification_requests');
    }
};
