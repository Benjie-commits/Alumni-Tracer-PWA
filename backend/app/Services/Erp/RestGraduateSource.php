<?php

namespace App\Services\Erp;

use App\Services\Erp\Contracts\GraduateSource;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads graduates from SorotiUniERP's REST API (spec section 7.1, first integration mode).
 *
 * The contract is deliberately plain so the ERP team can meet it without special work: a GET that
 * takes page / per_page and an optional updated_since, and answers {"data": [ {...}, ... ]}. Every
 * name in that sentence is configurable (see config/sunates.php), because the ERP was not written
 * with this system in mind.
 */
class RestGraduateSource implements GraduateSource
{
    /** A stop for an ERP that ignores paging and would otherwise be asked forever. */
    private const MAX_PAGES = 2000;

    public function fetch(?CarbonInterface $since, ?int $limit = null): Generator
    {
        $config = (array) config('sunates.erp.rest');
        $url = $this->url($config);
        $size = max(1, min($limit ?? PHP_INT_MAX, (int) ($config['page_size'] ?: 200)));

        $emitted = 0;
        $previousFirst = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = [$config['page_param'] => $page, $config['size_param'] => $size];
            if ($since !== null && $config['since_param']) {
                $query[$config['since_param']] = $since->toIso8601String();
            }

            $records = $this->list($this->get($url, $query, $config), $config);

            // An empty page is the only reliable "that was the last one": an ERP that caps page size
            // below what we asked for would otherwise look finished after page 1.
            if ($records === []) {
                return;
            }

            $first = json_encode(reset($records));
            if ($first === $previousFirst) {
                throw new ErpSourceException("The ERP sent the same records for page {$page} as for the page before, so it seems to ignore paging. Check ERP_REST_PAGE_PARAM and ERP_REST_SIZE_PARAM.");
            }
            $previousFirst = $first;

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                yield $record;

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }
        }

        throw new ErpSourceException('The ERP kept sending pages after '.self::MAX_PAGES.'; stopping so a paging fault cannot run forever.');
    }

    /** @param  array<string, mixed>  $config */
    private function url(array $config): string
    {
        $base = trim((string) ($config['base_url'] ?? ''));
        if ($base === '') {
            throw new ErpSourceException('ERP_REST_BASE_URL is not set.');
        }

        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new ErpSourceException('ERP_REST_BASE_URL is not a valid web address.');
        }

        // The credential travels with every request, so it never goes over an unencrypted link
        // (a machine's own loopback address is the one exception, for local development).
        if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            throw new ErpSourceException('ERP_REST_BASE_URL must start with https:// so the ERP credential is not sent in the clear.');
        }

        return rtrim($base, '/').'/'.ltrim((string) ($config['path'] ?? ''), '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $config
     */
    private function get(string $url, array $query, array $config): Response
    {
        try {
            $response = $this->client($config)->get($url, $query);
        } catch (ConnectionException $e) {
            throw new ErpSourceException('Could not reach the ERP: '.mb_strimwidth($e->getMessage(), 0, 200, '…'), 0, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        // The status alone is shown: an error page can carry details we should not copy into our records.
        throw new ErpSourceException(match (true) {
            in_array($response->status(), [401, 403], true) => "The ERP refused our credentials (HTTP {$response->status()}). Check ERP_REST_TOKEN and ERP_REST_AUTH.",
            $response->status() === 404 => 'The ERP has no such address (HTTP 404). Check ERP_REST_BASE_URL and ERP_REST_PATH.',
            default => "The ERP answered with an error (HTTP {$response->status()}).",
        });
    }

    /** @param  array<string, mixed>  $config */
    private function client(array $config): PendingRequest
    {
        $client = Http::acceptJson()->timeout(max(1, (int) $config['timeout']));

        $auth = (string) $config['auth'];
        if ($auth === 'none') {
            return $client;
        }

        if (! in_array($auth, ['bearer', 'header'], true)) {
            throw new ErpSourceException("Unknown ERP_REST_AUTH '{$auth}' (use bearer, header or none).");
        }

        $token = (string) ($config['token'] ?? '');
        if ($token === '') {
            throw new ErpSourceException('ERP_REST_TOKEN is not set.');
        }

        return $auth === 'bearer' ? $client->withToken($token) : $client->withHeaders([(string) $config['token_header'] => $token]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<mixed>
     */
    private function list(Response $response, array $config): array
    {
        $key = (string) $config['data_key'];
        $list = $key === '' ? $response->json() : $response->json($key);

        if (! is_array($list) || ($list !== [] && ! array_is_list($list))) {
            throw new ErpSourceException($key === ''
                ? 'The ERP answered, but not with a list of graduates.'
                : "The ERP answered, but there is no list of graduates under \"{$key}\". Check ERP_REST_DATA_KEY.");
        }

        return $list;
    }
}
