<?php

namespace App\Services\Erp;

use RuntimeException;

/** The ERP could not be read. The message is written for staff and never contains credentials. */
class ErpSourceException extends RuntimeException {}
