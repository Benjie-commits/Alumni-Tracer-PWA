<?php

namespace App\Services\Registration;

use RuntimeException;

/** Every route to the contact details on record failed for a temporary reason: try again shortly. */
class RegistrationCodeNotSent extends RuntimeException {}
