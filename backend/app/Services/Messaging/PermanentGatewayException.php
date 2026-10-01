<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * The provider refused this message and trying again will not help (bad number, not on WhatsApp,
 * template not approved, missing credentials). The notification service moves on to the next channel.
 */
class PermanentGatewayException extends RuntimeException {}
