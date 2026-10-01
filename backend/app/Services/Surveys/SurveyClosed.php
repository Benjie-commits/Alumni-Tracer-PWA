<?php

namespace App\Services\Surveys;

use RuntimeException;

/** The survey window has closed, so it can no longer be answered. */
class SurveyClosed extends RuntimeException {}
