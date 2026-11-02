<?php

/**
 * Contract for the HTTP transport used by sources.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

use OddsComparison\Http\HttpException;
use OddsComparison\Http\HttpResponse;

defined('ABSPATH') || exit;

/**
 * Fetches a URL (Strategy: live WordPress HTTP API or recorded responses in Demo mode, chosen by `data_mode`).
 *
 * Implementations own transport concerns: timeouts, response size cap, retries, throttling and
 * the circuit breaker. Sources only see a successful response or an exception.
 */
interface HttpClientInterface
{
    /**
     * GET a URL.
     *
     * @param string $url Absolute URL built by a source from its hardcoded base.
     * @return HttpResponse A 2xx response.
     * @throws HttpException On transport errors, non-2xx responses, bot challenges, oversized
     *                       bodies or an open circuit.
     */
    public function get(string $url): HttpResponse;
}
