<?php

/**
 * Counts the requests that reach the transport.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use OddsComparison\Contracts\HttpClientInterface;

defined('ABSPATH') || exit;

/**
 * Decorator that counts every request handed to the wrapped transport, successful or not.
 *
 * Sources memoize responses per run, so many refresh steps can share one request (every match
 * of a competition is priced from one listing). The refresh run limits `requests_per_run` with
 * this count instead of counting steps, so steps that cost nothing do not use up the budget
 * and leave pairs for a later run that would have to request (and pay for) the page again.
 */
final class CountingHttpClient implements HttpClientInterface
{
    /** Requests handed to the wrapped transport. */
    private int $count = 0;

    /**
     * @param HttpClientInterface $inner Wrapped transport.
     */
    public function __construct(private readonly HttpClientInterface $inner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $url): HttpResponse
    {
        ++$this->count;

        try {
            return $this->inner->get($url);
        } catch (TimeBudgetExceeded $exception) {
            --$this->count; // Refused before anything was sent.

            throw $exception;
        }
    }

    /**
     * Requests so far in this PHP request.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->count;
    }
}
