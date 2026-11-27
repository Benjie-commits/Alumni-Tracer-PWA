<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A graduate's own public page. It exists only when they publish it (public_published_at), and they
     * choose which optional parts show (public_sections); name, programme and graduation year are the
     * Registrar-verified baseline. The slug is server-made, never chosen by a client.
     */
    public function up(): void
    {
        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->string('public_slug', 90)->nullable()->unique();
            $table->string('public_headline', 160)->nullable();
            $table->json('public_sections')->nullable();
            $table->timestamp('public_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alumni_profiles', function (Blueprint $table) {
            $table->dropUnique(['public_slug']);
            $table->dropColumn(['public_slug', 'public_headline', 'public_sections', 'public_published_at']);
        });
    }
};
