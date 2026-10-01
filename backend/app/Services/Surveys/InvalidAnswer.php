<?php

namespace App\Services\Surveys;

use RuntimeException;

/** One answer failed its question's rules; the message is shown to the alumnus. */
class InvalidAnswer extends RuntimeException {}
