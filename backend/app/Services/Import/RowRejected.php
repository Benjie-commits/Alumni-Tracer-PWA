<?php

namespace App\Services\Import;

use RuntimeException;

/** A spreadsheet row that cannot be imported; the message is shown to staff verbatim. */
class RowRejected extends RuntimeException {}
