<?php

namespace App\Console\Commands;

use App\Services\Surveys\SurveyDefinitionSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('sunates:sync-surveys')]
#[Description('Load tracer-survey questionnaires from config/tracer_surveys.php, creating a new version when they changed')]
class SyncSurveys extends Command
{
    public function handle(SurveyDefinitionSync $sync): int
    {
        try {
            $results = $sync->sync();
        } catch (InvalidArgumentException $e) {
            $this->error('Survey definitions are invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $months => $outcome) {
            $this->line(sprintf('%2d-month survey: %s', $months, $outcome));
        }

        return self::SUCCESS;
    }
}
