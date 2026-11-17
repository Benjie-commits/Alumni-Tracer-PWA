<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FR-2: proving that the person registering is the graduate in the Registrar's record. Student number,
     * surname and year are often known to classmates, so a match alone no longer claims the record: a
     * one-time code goes to the phone or email the Registrar already holds, and the record is claimed
     * only when that code comes back. Nothing is created until then.
     */
    public function up(): void
    {
        Schema::create('registration_challenges', function (Blueprint $table) {
            $table->id();
            // What the app holds between "send me a code" and "here is the code". Not the code itself.
            $table->string('token', 64)->unique();
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->string('channel', 16);
            // A masked form ("•••••••456") shown to the person; the full number or address is never returned.
            $table->string('destination_hint', 64);
            // What they typed at registration (email, phone, password already hashed), encrypted at rest.
            $table->text('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('sends')->default(1);
            $table->timestamp('last_sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['alumni_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_challenges');
    }
};
