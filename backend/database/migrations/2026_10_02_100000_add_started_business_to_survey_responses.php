<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entrepreneurship is one of the three outcomes the dashboards report (FR-4), so it gets its own
     * column beside employment and further-study status instead of being dug out of the answers JSON
     * on every page view.
     */
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->boolean('started_business')->nullable()->after('ta_interest');
        });

        // Responses collected before this column existed: fill it from the stored answers.
        DB::table('survey_responses')->update([
            'started_business' => DB::raw(
                "CASE JSON_UNQUOTE(JSON_EXTRACT(answers, '$.started_business')) WHEN 'true' THEN 1 WHEN 'false' THEN 0 ELSE NULL END"
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn('started_business');
        });
    }
};
