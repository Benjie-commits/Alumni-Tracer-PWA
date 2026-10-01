<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per attempt to read graduates from SorotiUniERP (FR-9), whether it was a preview,
        // a real sync, or one that failed. It is what lets ICT answer "did last night's sync work?".
        Schema::create('erp_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 16);
            $table->string('trigger', 16); // scheduled | manual | cli
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('dry_run')->default(false);
            $table->boolean('full')->default(false);
            $table->string('status', 16)->index();

            // What we asked for, and the newest change the ERP told us about (the next sync starts there).
            $table->timestamp('since')->nullable();
            $table->timestamp('cursor')->nullable();

            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('not_graduated')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('rejected')->default(0);

            $table->text('error')->nullable();
            $table->json('report')->nullable(); // problems and warnings, capped

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sync_runs');
    }
};
