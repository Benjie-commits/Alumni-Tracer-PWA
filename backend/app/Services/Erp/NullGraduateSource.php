<?php

namespace App\Services\Erp;

use App\Services\Erp\Contracts\GraduateSource;
use Carbon\CarbonInterface;

/** Standalone mode (ERP_DRIVER=none): there is no ERP to read, and saying so beats quietly returning nothing. */
class NullGraduateSource implements GraduateSource
{
    public function fetch(?CarbonInterface $since, ?int $limit = null): iterable
    {
        throw new ErpSourceException('The SorotiUniERP integration is switched off (ERP_DRIVER=none).');
    }
}
