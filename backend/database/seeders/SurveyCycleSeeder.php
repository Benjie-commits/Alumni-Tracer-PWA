<?php

namespace Database\Seeders;

use App\Services\Surveys\SurveyDefinitionSync;
use Illuminate\Database\Seeder;

class SurveyCycleSeeder extends Seeder
{
    /**
     * Load the 6-month, 1-year and 3-year questionnaires from config/tracer_surveys.php.
     * Idempotent and safe in production: unchanged questions do nothing.
     */
    public function run(SurveyDefinitionSync $sync): void
    {
        $sync->sync();
    }
}
