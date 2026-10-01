<?php

namespace App\Services\Surveys;

use RuntimeException;

/** This invitation was already answered (with a different submission). */
class SurveyAlreadyCompleted extends RuntimeException {}
