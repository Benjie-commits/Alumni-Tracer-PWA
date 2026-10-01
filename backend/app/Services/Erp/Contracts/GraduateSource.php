<?php

namespace App\Services\Erp\Contracts;

use App\Services\Erp\ErpSourceException;
use Carbon\CarbonInterface;

/**
 * Where SUN-ATES reads graduating students from once SorotiUniERP is live (spec section 7.1).
 *
 * Everything the rest of the system knows about the ERP is this one method, so switching from
 * standalone mode to the REST API or to a database view changes nothing in the profile or survey code.
 */
interface GraduateSource
{
    /**
     * The ERP's own records, untouched: the field names are the ERP's, and turning them into ours is
     * the GraduateMapper's job. Records changed since $since only, when the ERP can say.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws ErpSourceException when the ERP cannot be read; the message is safe to show to staff
     */
    public function fetch(?CarbonInterface $since, ?int $limit = null): iterable;
}
