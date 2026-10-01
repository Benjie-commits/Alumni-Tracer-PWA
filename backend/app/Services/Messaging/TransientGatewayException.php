<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * A temporary problem (timeout, provider outage, rate limit, expired token). The queued job is
 * retried with back-off rather than falling through to another channel, which would risk
 * messaging the same person twice.
 */
class TransientGatewayException extends RuntimeException {}
