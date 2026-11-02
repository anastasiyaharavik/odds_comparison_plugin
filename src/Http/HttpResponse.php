<?php

/**
 * Successful HTTP response.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

defined('ABSPATH') || exit;

/**
 * A 2xx response as seen by sources: status, body and lower-cased headers.
 */
final class HttpResponse
{
    /**
     * Headers with lower-cased names.
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * @param int                   $status  HTTP status (2xx).
     * @param string                $body    Response body.
     * @param array<string, string> $headers Response headers (any case).
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = []
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /**
     * One header value, or null when absent.
     *
     * @param string $name Header name (case-insensitive).
     * @return string|null
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
