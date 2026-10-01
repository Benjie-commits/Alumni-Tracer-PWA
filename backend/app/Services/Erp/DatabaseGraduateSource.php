<?php

namespace App\Services\Erp;

use App\Services\Erp\Contracts\GraduateSource;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads graduates from a view in SorotiUniERP's database (spec section 7.1, second integration mode).
 *
 * Only SELECTs are ever issued, and only for the columns the field map names. The database user
 * should be read-only anyway; the view should expose graduates and nothing else.
 */
class DatabaseGraduateSource implements GraduateSource
{
    private const CHUNK = 500;

    public function __construct(private readonly GraduateMapper $mapper) {}

    public function fetch(?CarbonInterface $since, ?int $limit = null): Generator
    {
        $table = $this->identifier((string) config('sunates.erp.database.table'), 'ERP_DB_TABLE');
        $columns = array_map(fn (string $c) => $this->identifier($c, 'ERP_FIELD_MAP'), $this->mapper->sourceFields());

        $key = $this->mapper->field('student_number');
        if ($key === null) {
            throw new ErpSourceException('The field map has no student_number column, so records cannot be matched.');
        }
        $updated = $this->mapper->field('updated_at');

        try {
            $connection = DB::connection(config('sunates.erp.database.connection') ?: null);
            $emitted = 0;

            for ($offset = 0; ; $offset += self::CHUNK) {
                $query = $connection->table($table)->select($columns)->orderBy($key)->offset($offset)->limit(self::CHUNK);

                if ($since !== null && $updated !== null) {
                    // The ERP's clock is read as Uganda time; the overlap in the sync absorbs any difference.
                    $query->where($updated, '>=', $since->copy()->timezone(config('sunates.timezone'))->format('Y-m-d H:i:s'));
                }

                $rows = $query->get();

                foreach ($rows as $row) {
                    yield (array) $row;

                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                }

                if ($rows->count() < self::CHUNK) {
                    return;
                }
            }
        } catch (QueryException $e) {
            throw new ErpSourceException('Could not read the ERP database: '.mb_strimwidth((string) ($e->getPrevious()?->getMessage() ?? 'query failed'), 0, 200, '…'), 0, $e);
        } catch (InvalidArgumentException $e) {
            throw new ErpSourceException('The ERP database connection is not set up: '.$e->getMessage(), 0, $e);
        }
    }

    /** Names come from config, not from users, but a typo that reaches SQL should fail loudly instead. */
    private function identifier(string $name, string $setting): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name) !== 1) {
            throw new ErpSourceException("'{$name}' ({$setting}) is not a plain table or column name.");
        }

        return $name;
    }
}
