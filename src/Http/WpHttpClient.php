<?php

/**
 * Live HTTP transport over the WordPress HTTP API.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use Closure;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Support\Deadline;

defined('ABSPATH') || exit;

/**
 * GETs through `wp_safe_remote_get()` with throttling, retries and a circuit breaker.
 *
 * - One honest User-Agent for every source; headers are never tuned to pass bot protection.
 * - Timeouts and 5xx: up to 2 retries after 1 s and 3 s.
 * - 429/503 with Retry-After: the host is paused for that long (max 1 h), no retry.
 * - Bot challenges (e.g. Cloudflare `cf-mitigated: challenge`), 401/403, 429 and exhausted
 *   retries count as breaker failures. Other 4xx (e.g. 404) are page problems and do not.
 * - Bodies at or above the size cap are rejected.
 * - `apiKey` values are redacted from every error message.
 * - Inside a refresh run, the run's {@see Deadline} shortens the timeout to the time left and
 *   skips attempts that could not finish in time. A first attempt skipped this way
 *   throws {@see TimeBudgetExceeded} and is not a failure; skipped retries are.
 */
final class WpHttpClient implements HttpClientInterface
{
    /** Response size cap (real outright pages reach 2.1 MB). */
    public const MAX_BYTES = 4194304;

    /** Request timeout in seconds. */
    private const TIMEOUT = 15;

    /** Shortest time worth starting an attempt with, in seconds. */
    public const MIN_ATTEMPT_SECONDS = 5;

    /** Delays before each retry, in seconds. */
    private const RETRY_DELAYS = [1, 3];

    /** Longest Retry-After pause honoured, in seconds. */
    private const MAX_RETRY_AFTER = 3600;

    /** Body markers of bot-challenge pages. */
    private const CHALLENGE_MARKERS =
        '/<title>\s*(Just a moment\.\.\.|Attention Required!)|cf-chl-|challenge-platform/i';

    private readonly Closure $sleep;

    private readonly Deadline $deadline;

    /**
     * @param RateLimiter    $rateLimiter    Per-host throttle.
     * @param CircuitBreaker $circuitBreaker Per-host breaker.
     * @param string         $userAgent      Honest plugin User-Agent.
     * @param Closure|null   $sleep          fn(int $seconds): void for retry backoff (tests).
     * @param Deadline|null  $deadline       Deadline of the current refresh run (none by default).
     */
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly string $userAgent,
        ?Closure $sleep = null,
        ?Deadline $deadline = null
    ) {
        $this->sleep    = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->deadline = $deadline ?? new Deadline();
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $url): HttpResponse
    {
        $host  = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $shown = self::redact($url);

        if ($host === '') {
            throw new HttpException(sprintf('Invalid URL %s.', esc_url_raw($shown)));
        }

        if (!$this->circuitBreaker->allowRequest($host)) {
            throw new HttpException(sprintf('Requests to %s are paused (circuit open).', $host));
        }

        $attempt = 0;

        if (!$this->hasTimeFor($host, 0)) {
            throw new TimeBudgetExceeded(
                sprintf('Not requested %s: the refresh run is out of time.', esc_url_raw($shown))
            );
        }

        while (true) {
            $this->rateLimiter->wait($host);
            $response = wp_safe_remote_get($url, $this->requestArgs($url));
            $retry    = $attempt < count(self::RETRY_DELAYS)
                && $this->hasTimeFor($host, self::RETRY_DELAYS[$attempt]);

            if (is_wp_error($response)) {
                $reason = 'transport error: ' . self::redact($response->get_error_message());
            } else {
                $status  = (int) wp_remote_retrieve_response_code($response);
                $body    = (string) wp_remote_retrieve_body($response);
                $headers = $this->headers($response);

                $result = $this->handle($host, $shown, $status, $body, $headers);

                if ($result instanceof HttpResponse) {
                    return $result;
                }

                $reason = $result;
            }

            if (!$retry) {
                $this->circuitBreaker->recordFailure($host, $reason);

                throw new HttpException(sprintf('%s failed after retries: %s', esc_url_raw($shown), $reason));
            }

            ($this->sleep)(self::RETRY_DELAYS[$attempt]);
            ++$attempt;
        }
    }

    /**
     * Whether an attempt after the given pause, the throttle wait and a minimal request still fits
     * the run's deadline.
     *
     * @param string $host  Host.
     * @param int    $pause Seconds slept before the attempt.
     * @return bool
     */
    private function hasTimeFor(string $host, int $pause): bool
    {
        return $this->deadline->allows($pause + $this->rateLimiter->longestWait($host) + self::MIN_ATTEMPT_SECONDS);
    }

    /**
     * Mask API keys in a URL before it reaches a message or log.
     *
     * @param string $url URL.
     * @return string
     */
    public static function redact(string $url): string
    {
        return (string) preg_replace('/([?&]apiKey=)[^&#]*/i', '$1REDACTED', $url);
    }

    /**
     * Turn one response into a result, an exception, or a retryable reason.
     *
     * @param string                $host    Host.
     * @param string                $shown   Redacted URL.
     * @param int                   $status  HTTP status.
     * @param string                $body    Body.
     * @param array<string, string> $headers Lower-cased headers.
     * @return HttpResponse|string Response, or the reason of a retryable failure.
     * @throws HttpException On non-retryable failures.
     */
    private function handle(string $host, string $shown, int $status, string $body, array $headers): HttpResponse|string
    {
        if ($this->isChallenge($status, $body, $headers)) {
            $this->fail(
                $host,
                sprintf('HTTP %d: blocked by bot protection (challenge page)', $status),
                $shown,
                $status
            );
        }

        if ($status >= 200 && $status < 300) {
            if (strlen($body) >= self::MAX_BYTES) {
                $this->fail($host, 'response exceeds the size cap', $shown, $status);
            }

            $this->circuitBreaker->recordSuccess($host);

            return new HttpResponse($status, $body, $headers);
        }

        $retryAfter = $this->retryAfter($headers['retry-after'] ?? '');

        if (($status === 429 || $status === 503) && $retryAfter !== null) {
            $reason = sprintf('HTTP %d, Retry-After %d s', $status, $retryAfter);
            $this->circuitBreaker->pause($host, $retryAfter, $reason);

            throw new HttpException(
                sprintf('%s answered HTTP %d; paused for %d s.', esc_url_raw($shown), (int) $status, (int) $retryAfter),
                (int) $status,
                (int) $retryAfter
            );
        }

        if ($status >= 500) {
            return sprintf('HTTP %d', $status);
        }

        if ($status === 401 || $status === 403 || $status === 429) {
            $this->fail($host, sprintf('HTTP %d', $status), $shown, $status);
        }

        throw new HttpException(sprintf('%s answered HTTP %d.', esc_url_raw($shown), (int) $status), (int) $status);
    }

    /**
     * Record a breaker failure and throw.
     *
     * @param string $host   Host.
     * @param string $reason Reason.
     * @param string $shown  Redacted URL.
     * @param int    $status HTTP status.
     * @return never
     * @throws HttpException Always.
     */
    private function fail(string $host, string $reason, string $shown, int $status): never
    {
        $this->circuitBreaker->recordFailure($host, $reason);

        throw new HttpException(sprintf('%s: %s.', esc_url_raw($shown), $reason), (int) $status);
    }

    /**
     * Whether the response is a bot-challenge page.
     *
     * @param int                   $status  HTTP status.
     * @param string                $body    Body.
     * @param array<string, string> $headers Lower-cased headers.
     * @return bool
     */
    private function isChallenge(int $status, string $body, array $headers): bool
    {
        if (($headers['cf-mitigated'] ?? '') === 'challenge') {
            return true;
        }

        return in_array($status, [403, 429, 503], true) && preg_match(self::CHALLENGE_MARKERS, $body) === 1;
    }

    /**
     * Seconds from a Retry-After header (delta-seconds or HTTP date), capped; null when absent.
     *
     * @param string $value Header value.
     * @return int|null
     */
    private function retryAfter(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $seconds = ctype_digit($value) ? (int) $value : (int) strtotime($value) - time();

        return $seconds > 0 ? min($seconds, self::MAX_RETRY_AFTER) : null;
    }

    /**
     * Request arguments, filterable via `odds_comparison/http_args`.
     *
     * @param string $url URL (passed to the filter).
     * @return array<string, mixed>
     */
    private function requestArgs(string $url): array
    {
        $defaults = [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'user-agent'          => $this->userAgent,
            'limit_response_size' => self::MAX_BYTES,
            'reject_unsafe_urls'  => true,
        ];

        /**
         * Filters the HTTP request arguments for source requests.
         *
         * @param array<string, mixed> $args Arguments for wp_safe_remote_get().
         * @param string               $url  Request URL (may contain an API key; never log it).
         */
        $args      = apply_filters('odds_comparison/http_args', $defaults, $url);
        $args      = is_array($args) ? $args + $defaults : $defaults;
        $remaining = $this->deadline->remaining();

        if ($remaining !== null) {
            $args['timeout'] = max(1, min((int) $args['timeout'], (int) floor($remaining)));
        }

        return $args;
    }

    /**
     * Response headers as a lower-cased string map.
     *
     * WordPress returns a `CaseInsensitiveDictionary` object, not an array, so it is iterated
     * rather than cast (a cast would silently yield no headers).
     *
     * @param array<string, mixed> $response wp_remote_get() response.
     * @return array<string, string>
     */
    private function headers(array $response): array
    {
        $raw     = wp_remote_retrieve_headers($response);
        $headers = [];

        if (!is_iterable($raw)) {
            return [];
        }

        foreach ($raw as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return $headers;
    }
}
