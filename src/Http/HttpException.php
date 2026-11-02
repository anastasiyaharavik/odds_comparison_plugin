<?php

/**
 * HTTP failure raised by HttpClientInterface implementations.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use RuntimeException;
use Throwable;

defined('ABSPATH') || exit;

/**
 * A failed request, carrying what the rate limiter and circuit breaker need to react.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param string         $message    Human-readable reason (no PII, no response body).
     * @param int            $statusCode HTTP status, or 0 for transport errors (timeout, DNS, open circuit).
     * @param int|null       $retryAfter Seconds the server asked us to wait (`Retry-After`), if any.
     * @param Throwable|null $previous   Underlying exception.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
