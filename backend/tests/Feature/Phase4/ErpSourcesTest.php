<?php

namespace Tests\Feature\Phase4;

use App\Services\Erp\Contracts\GraduateSource;
use App\Services\Erp\DatabaseGraduateSource;
use App\Services\Erp\ErpSourceException;
use App\Services\Erp\NullGraduateSource;
use App\Services\Erp\RestGraduateSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class ErpSourcesTest extends Phase4TestCase
{
    /** @return list<array<string, mixed>> */
    private function fetchAll(?Carbon $since = null, ?int $limit = null): array
    {
        return iterator_to_array(app(GraduateSource::class)->fetch($since, $limit), false);
    }

    private function records(int $n): array
    {
        return array_map(fn (int $i) => $this->erpRecord(['student_number' => sprintf('SU/2026/%03d', $i)]), range(1, $n));
    }

    // ---- choosing the driver -------------------------------------------------------------

    public function test_the_driver_setting_picks_the_source(): void
    {
        foreach (['none' => NullGraduateSource::class, 'rest' => RestGraduateSource::class, 'database' => DatabaseGraduateSource::class] as $driver => $class) {
            config(['sunates.erp.driver' => $driver]);
            $this->assertInstanceOf($class, app(GraduateSource::class));
        }
    }

    public function test_an_unknown_driver_is_an_error_not_a_silent_fallback(): void
    {
        config(['sunates.erp.driver' => 'restt']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown ERP_DRIVER 'restt'");
        app(GraduateSource::class);
    }

    public function test_standalone_mode_refuses_to_pretend_there_is_an_erp(): void
    {
        config(['sunates.erp.driver' => 'none']);

        $this->expectException(ErpSourceException::class);
        $this->fetchAll();
    }

    // ---- REST ------------------------------------------------------------------------------

    public function test_rest_pages_through_the_feed_until_an_empty_page(): void
    {
        $this->useRestErp();
        $this->fakeErp($this->records(5));

        $all = $this->fetchAll();

        $this->assertCount(5, $all);
        $this->assertSame(['SU/2026/001', 'SU/2026/005'], [$all[0]['student_number'], $all[4]['student_number']]);
        $this->assertSame(['1', '2', '3', '4'], array_column(array_column($this->erpRequests, 'query'), 'page'), '5 records in pages of 2, then one empty page to be sure');
    }

    public function test_an_erp_that_caps_page_size_below_what_we_ask_is_still_read_in_full(): void
    {
        $this->useRestErp(['rest.page_size' => 200]);
        $records = $this->records(7);

        // This ERP ignores per_page and always sends 3.
        Http::fake(function ($request) use ($records) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(['data' => array_slice($records, ((int) $q['page'] - 1) * 3, 3)]);
        });

        $this->assertCount(7, $this->fetchAll(), 'a short page must not be mistaken for the last page');
    }

    public function test_it_sends_the_bearer_token_and_asks_for_changes_since_a_date(): void
    {
        $this->useRestErp();
        $this->fakeErp($this->records(1));

        $this->fetchAll(Carbon::parse('2026-10-04 08:30:00', 'Africa/Kampala'));

        $this->assertSame('Bearer erp-secret-token', $this->erpRequests[0]['auth']);
        $this->assertSame('2026-10-04T08:30:00+03:00', $this->erpRequests[0]['query']['updated_since']);
    }

    public function test_it_asks_for_everything_when_there_is_no_since_date(): void
    {
        $this->useRestErp();
        $this->fakeErp($this->records(1));

        $this->fetchAll();

        $this->assertArrayNotHasKey('updated_since', $this->erpRequests[0]['query']);
    }

    public function test_a_header_credential_and_custom_parameter_names_are_supported(): void
    {
        $this->useRestErp([
            'rest.auth' => 'header', 'rest.token_header' => 'X-Api-Key',
            'rest.page_param' => 'pageNumber', 'rest.size_param' => 'pageSize', 'rest.since_param' => 'modifiedAfter',
        ]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen = ['key' => $request->header('X-Api-Key')[0] ?? null, 'bearer' => $request->header('Authorization')[0] ?? null, 'url' => $request->url()];

            return Http::response(['data' => []]);
        });

        $this->fetchAll(Carbon::parse('2026-10-04 08:30:00', 'Africa/Kampala'));

        $this->assertSame('erp-secret-token', $seen['key']);
        $this->assertNull($seen['bearer']);
        parse_str((string) parse_url($seen['url'], PHP_URL_QUERY), $q);
        $this->assertSame('1', $q['pageNumber']);
        $this->assertSame('2', $q['pageSize']);
        $this->assertArrayHasKey('modifiedAfter', $q);
    }

    public function test_a_limit_stops_reading_early(): void
    {
        $this->useRestErp();
        $this->fakeErp($this->records(10));

        $this->assertCount(1, $this->fetchAll(null, 1));
        $this->assertCount(1, $this->erpRequests, 'one record needs one request, of size 1');
        $this->assertSame('1', $this->erpRequests[0]['query']['per_page']);
    }

    public function test_the_list_can_be_the_whole_answer(): void
    {
        $this->useRestErp(['rest.data_key' => '']);
        Http::fake(fn ($r) => str_contains($r->url(), 'page=1&') ? Http::response([$this->erpRecord()]) : Http::response([]));

        $this->assertCount(1, $this->fetchAll());
    }

    public function test_the_list_can_sit_under_a_nested_key(): void
    {
        $this->useRestErp(['rest.data_key' => 'result.items']);
        Http::fake(fn ($r) => str_contains($r->url(), 'page=1&')
            ? Http::response(['result' => ['items' => [$this->erpRecord()]]])
            : Http::response(['result' => ['items' => []]]));

        $this->assertCount(1, $this->fetchAll());
    }

    public function test_an_answer_that_is_not_a_list_of_graduates_is_reported(): void
    {
        $this->useRestErp();
        Http::fake(['*' => Http::response(['message' => 'ok'])]);

        $this->expectException(ErpSourceException::class);
        $this->expectExceptionMessage('no list of graduates under "data"');
        $this->fetchAll();
    }

    public function test_an_erp_that_ignores_paging_is_caught_instead_of_looping(): void
    {
        $this->useRestErp();
        $same = $this->records(2);
        Http::fake(['*' => Http::response(['data' => $same])]);

        $this->expectException(ErpSourceException::class);
        $this->expectExceptionMessage('seems to ignore paging');
        $this->fetchAll();
    }

    public function test_credentials_are_never_sent_over_plain_http_to_another_machine(): void
    {
        $this->useRestErp(['rest.base_url' => 'http://erp.soroti.example']);
        Http::fake();

        try {
            $this->fetchAll();
            $this->fail('expected a refusal');
        } catch (ErpSourceException $e) {
            $this->assertStringContainsString('https://', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_plain_http_is_fine_on_the_machine_itself_for_development(): void
    {
        $this->useRestErp(['rest.base_url' => 'http://127.0.0.1:9000']);
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->assertSame([], $this->fetchAll());
    }

    public function test_a_missing_address_or_token_is_explained(): void
    {
        $this->useRestErp(['rest.base_url' => '']);
        try {
            $this->fetchAll();
            $this->fail('expected an error');
        } catch (ErpSourceException $e) {
            $this->assertStringContainsString('ERP_REST_BASE_URL is not set', $e->getMessage());
        }

        $this->useRestErp(['rest.token' => '']);
        Http::fake();
        try {
            $this->fetchAll();
            $this->fail('expected an error');
        } catch (ErpSourceException $e) {
            $this->assertStringContainsString('ERP_REST_TOKEN is not set', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_no_authentication_is_allowed_when_asked_for_explicitly(): void
    {
        $this->useRestErp(['rest.auth' => 'none', 'rest.token' => '']);
        $this->fakeErp($this->records(1));

        $this->assertCount(1, $this->fetchAll());
        $this->assertSame('', $this->erpRequests[0]['auth']);
    }

    public function test_http_errors_become_staff_readable_messages_without_leaking_the_body(): void
    {
        $this->useRestErp();

        // One fake whose status changes: a second Http::fake() would never be consulted (the first match wins).
        $status = 0;
        Http::fake(function () use (&$status) {
            return Http::response('Stack trace: password=hunter2 for student SU/1', $status);
        });

        foreach ([401 => 'refused our credentials', 403 => 'refused our credentials', 404 => 'no such address', 500 => 'HTTP 500'] as $status => $expected) {
            try {
                $this->fetchAll();
                $this->fail("expected a failure for {$status}");
            } catch (ErpSourceException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
                $this->assertStringNotContainsString('hunter2', $e->getMessage());
                $this->assertStringNotContainsString('erp-secret-token', $e->getMessage());
            }
        }
    }

    public function test_an_unreachable_erp_is_reported(): void
    {
        $this->useRestErp();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out'));

        $this->expectException(ErpSourceException::class);
        $this->expectExceptionMessage('Could not reach the ERP');
        $this->fetchAll();
    }

    // ---- database view -----------------------------------------------------------------------

    /**
     * A stand-in for the ERP's view. TEMPORARY, because creating an ordinary table would commit the
     * test's transaction (MySQL DDL) and leak data into the next test.
     */
    private function erpView(array $rows, string $keyColumn = 'reg_no'): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS erp_graduates_view');
        DB::statement("CREATE TEMPORARY TABLE erp_graduates_view (
            {$keyColumn} VARCHAR(40), given VARCHAR(80), family VARCHAR(80), course VARCHAR(120),
            conferred DATE NULL, modified DATETIME NULL, secret_column VARCHAR(40) NULL
        )");

        foreach ($rows as $row) {
            DB::table('erp_graduates_view')->insert($row);
        }

        config([
            'sunates.erp.driver' => 'database',
            'sunates.erp.database.connection' => null,
            'sunates.erp.database.table' => 'erp_graduates_view',
            'sunates.erp.fields' => array_replace(config('sunates.erp.fields'), [
                'student_number' => $keyColumn, 'first_name' => 'given', 'last_name' => 'family',
                'programme' => 'course', 'graduation_date' => 'conferred', 'updated_at' => 'modified',
                'other_names' => null, 'gender' => null, 'date_of_birth' => null, 'school' => null, 'department' => null,
                'graduation_year' => null, 'class_of_award' => null, 'email' => null, 'phone' => null,
            ]),
        ]);
    }

    private function viewRow(int $i, array $overrides = []): array
    {
        return $overrides + [
            'reg_no' => sprintf('SU/2026/%03d', $i), 'given' => 'Amina', 'family' => "Okello{$i}", 'course' => 'BSc Biology',
            'conferred' => '2026-07-15', 'modified' => '2026-10-01 09:00:00', 'secret_column' => 'internal',
        ];
    }

    public function test_database_reads_the_view_through_the_field_map_in_a_stable_order(): void
    {
        $this->erpView([$this->viewRow(3), $this->viewRow(1), $this->viewRow(2)]);

        $all = $this->fetchAll();

        $this->assertSame(['SU/2026/001', 'SU/2026/002', 'SU/2026/003'], array_column($all, 'reg_no'));
        $this->assertSame('BSc Biology', $all[0]['course']);
    }

    public function test_database_selects_only_the_mapped_columns(): void
    {
        $this->erpView([$this->viewRow(1)]);

        $this->assertArrayNotHasKey('secret_column', $this->fetchAll()[0], 'data we do not use is never read');
    }

    public function test_database_reads_past_one_chunk_without_losing_or_repeating_rows(): void
    {
        $rows = array_map(fn ($i) => $this->viewRow($i), range(1, 1203));
        $this->erpView([]);
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('erp_graduates_view')->insert($chunk);
        }

        $all = $this->fetchAll();

        $this->assertCount(1203, $all);
        $this->assertCount(1203, array_unique(array_column($all, 'reg_no')));
    }

    public function test_database_asks_only_for_rows_changed_since_a_date(): void
    {
        $this->erpView([
            $this->viewRow(1, ['modified' => '2026-09-01 09:00:00']),
            $this->viewRow(2, ['modified' => '2026-10-04 09:00:00']),
            $this->viewRow(3, ['modified' => null]),
        ]);

        $changed = $this->fetchAll(Carbon::parse('2026-10-03 00:00:00', 'Africa/Kampala'));

        $this->assertSame(['SU/2026/002'], array_column($changed, 'reg_no'));
    }

    public function test_database_honours_a_limit(): void
    {
        $this->erpView([$this->viewRow(1), $this->viewRow(2), $this->viewRow(3)]);

        $this->assertCount(2, $this->fetchAll(null, 2));
    }

    public function test_database_refuses_names_that_are_not_plain_identifiers(): void
    {
        $this->erpView([$this->viewRow(1)]);
        config(['sunates.erp.database.table' => 'erp_graduates_view; DROP TABLE users']);

        $this->expectException(ErpSourceException::class);
        $this->expectExceptionMessage('is not a plain table or column name');
        $this->fetchAll();
    }

    public function test_database_needs_a_student_number_column_in_the_map(): void
    {
        $this->erpView([$this->viewRow(1)]);
        config(['sunates.erp.fields.student_number' => null]);

        $this->expectException(ErpSourceException::class);
        $this->expectExceptionMessage('no student_number column');
        $this->fetchAll();
    }

    public function test_database_reports_a_bad_connection_or_missing_table_in_plain_words(): void
    {
        config(['sunates.erp.driver' => 'database', 'sunates.erp.database.connection' => 'no_such_connection']);
        try {
            $this->fetchAll();
            $this->fail('expected an error');
        } catch (ErpSourceException $e) {
            $this->assertStringContainsString('connection is not set up', $e->getMessage());
        }

        config(['sunates.erp.database.connection' => null, 'sunates.erp.database.table' => 'table_that_is_not_there']);
        try {
            $this->fetchAll();
            $this->fail('expected an error');
        } catch (ErpSourceException $e) {
            $this->assertStringContainsString('Could not read the ERP database', $e->getMessage());
        }
    }
}
