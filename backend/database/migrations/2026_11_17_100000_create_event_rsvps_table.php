<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** FR-5: who says they are coming to an event. One answer per person per event, changeable. */
    public function up(): void
    {
        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('response', 16); // going | maybe | no
            $table->timestamps();

            $table->unique(['event_posting_id', 'user_id'], 'event_rsvps_event_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
    }
};
