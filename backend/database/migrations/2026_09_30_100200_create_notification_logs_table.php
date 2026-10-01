<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * NotificationLog (spec section 6): channel, template and delivery status for every message.
     * The message text is deliberately NOT stored: survey messages carry a secret link, and staff
     * who can read this table must not be able to answer a survey in someone else's name.
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_profile_id')->nullable()->constrained()->nullOnDelete();
            // Null when nothing was attempted (opted out, or no usable number): status is then 'blocked'.
            $table->string('channel', 16)->nullable();
            $table->string('template', 32);
            $table->string('to_number', 32)->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->text('error')->nullable();
            $table->nullableMorphs('related');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('provider_message_id');
            $table->index(['status', 'created_at']);
            $table->index(['alumni_profile_id', 'template', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
