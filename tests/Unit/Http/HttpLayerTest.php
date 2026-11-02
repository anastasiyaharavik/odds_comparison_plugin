<?php

/**
 * Tests for RateLimiter, CircuitBreaker, WpHttpClient and RecordedHttpClient.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Http;

use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Http\HttpException;
use OddsComparison\Http\RateLimiter;
use OddsComparison\Http\RecordedHttpClient;
use OddsComparison\Http\TimeBudgetExceeded;
use OddsComparison\Http\WpHttpClient;
use OddsComparison\Support\Deadline;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class HttpLayerTest extends TestCase
{
    private const URL = 'https://www.bettingodds.com/football/premier-league';

    private const HOST = 'www.bettingodds.com';

    /** Fake clock in seconds, advanced by the fake sleep. */
    private float $now = 1000000.0;

    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $GLOBALS['odds_comparison_test_transients']    = [];
        $GLOBALS['odds_comparison_test_http_queue']    = [];
        $GLOBALS['odds_comparison_test_http_requests'] = [];
        remove_all_filters('odds_comparison/http_args');
    }

    private function limiter(float $interval = 3.0): RateLimiter
    {
        return new RateLimiter(
            $interval,
            1.0,
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now     += $seconds;
            },
            static fn (float $max): float => $max / 2
        );
    }

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(3, 3600, fn (): int => (int) $this->now);
    }

    private function client(?CircuitBreaker $breaker = null, ?Deadline $deadline = null): WpHttpClient
    {
        return new WpHttpClient(
            $this->limiter(),
            $breaker ?? $this->breaker(),
            'OddsComparisonPlugin/0.1.0 (+https://example.test/)',
            function (int $seconds): void {
                $this->sleeps[] = (float) $seconds;
                $this->now     += $seconds;
            },
            $deadline
        );
    }

    private function deadline(float $seconds): Deadline
    {
        $deadline = new Deadline(fn (): float => $this->now);
        $deadline->start($seconds);

        return $deadline;
    }

    /**
     * @param array<string, string> $headers Headers.
     */
    private static function queue(int $status, string $body = '', array $headers = []): void
    {
        $GLOBALS['odds_comparison_test_http_queue'][] = [
            'response' => ['code' => $status],
            'body'     => $body,
            'headers'  => $headers,
        ];
    }

    public function testThrottleKeepsTheIntervalPerHostAcrossInstances(): void
    {
        $limiter = $this->limiter();
        $limiter->wait(self::HOST);
        $first = $this->now;
        $limiter->wait(self::HOST);

        self::assertEqualsWithDelta(3.5, $this->now - $first, 0.001, '3 s interval + 0.5 s jitter');

        $limiter->wait('api.the-odds-api.com');
        self::assertCount(1, $this->sleeps, 'another host is not throttled');

        // A new instance (next cron run) still sees the last request via the transient.
        $this->now += 1.0;
        $this->limiter()->wait(self::HOST);
        self::assertEqualsWithDelta(2.5, end($this->sleeps), 0.001);
    }

    public function testBreakerLifecycleClosedOpenHalfOpenClosed(): void
    {
        $breaker = $this->breaker();

        foreach ([1, 2] as $failure) {
            $breaker->recordFailure(self::HOST, 'HTTP 500');
            self::assertTrue($breaker->allowRequest(self::HOST), "still closed after {$failure}");
        }

        $breaker->recordFailure(self::HOST, 'HTTP 500');
        self::assertFalse($breaker->allowRequest(self::HOST));
        self::assertSame(CircuitBreaker::OPEN, $breaker->state(self::HOST)['state']);

        $this->now += 3600;
        self::assertTrue($breaker->allowRequest(self::HOST), 'one probe after the open period');
        self::assertSame(CircuitBreaker::HALF_OPEN, $breaker->state(self::HOST)['state']);

        $breaker->recordFailure(self::HOST, 'HTTP 500');
        self::assertFalse($breaker->allowRequest(self::HOST), 'failed probe reopens');

        $this->now += 3600;
        self::assertTrue($breaker->allowRequest(self::HOST));
        $breaker->recordSuccess(self::HOST);
        self::assertSame(
            ['state' => CircuitBreaker::CLOSED, 'failures' => 0, 'openUntil' => 0, 'lastError' => 'HTTP 500'],
            $breaker->state(self::HOST)
        );

        $breaker->pause(self::HOST, 120, 'Retry-After');
        self::assertFalse($breaker->allowRequest(self::HOST));
        $breaker->reset(self::HOST);
        self::assertTrue($breaker->allowRequest(self::HOST));
    }

    public function testSuccessfulGet(): void
    {
        self::queue(200, '<html>ok</html>', ['Content-Type' => 'text/html', 'X-Requests-Remaining' => '497']);

        $response = $this->client()->get(self::URL);

        self::assertSame('<html>ok</html>', $response->body);
        self::assertSame('497', $response->header('x-requests-remaining'));

        $args = $GLOBALS['odds_comparison_test_http_requests'][0]['args'];
        self::assertSame('OddsComparisonPlugin/0.1.0 (+https://example.test/)', $args['user-agent']);
        self::assertSame(15, $args['timeout']);
        self::assertSame(WpHttpClient::MAX_BYTES, $args['limit_response_size']);
        self::assertTrue($args['reject_unsafe_urls']);
    }

    public function testHeaderObjectsLikeWordPressReturnsAreRead(): void
    {
        // Real WordPress hands back a CaseInsensitiveDictionary (Traversable), not an array.
        $GLOBALS['odds_comparison_test_http_queue'][] = [
            'response' => ['code' => 200],
            'body'     => '[]',
            'headers'  => new \ArrayObject(['X-Requests-Remaining' => '488']),
        ];

        self::assertSame('488', $this->client()->get(self::URL)->header('x-requests-remaining'));
    }

    public function testChallengeHeaderAloneIsDetected(): void
    {
        $GLOBALS['odds_comparison_test_http_queue'][] = [
            'response' => ['code' => 403],
            'body'     => '<html>blocked</html>',
            'headers'  => new \ArrayObject(['cf-mitigated' => 'challenge']),
        ];

        $this->expectExceptionMessage('bot protection');
        $this->client()->get(self::URL);
    }

    public function testThreeRateLimitedResponsesOpenTheBreaker(): void
    {
        $breaker = $this->breaker();
        $client  = $this->client($breaker);

        for ($i = 1; $i <= 3; $i++) {
            self::queue(429);

            try {
                $client->get(self::URL);
                self::fail('expected HttpException');
            } catch (HttpException $exception) {
                self::assertSame(429, $exception->statusCode);
            }
        }

        self::assertSame(CircuitBreaker::OPEN, $breaker->state(self::HOST)['state']);

        $requests = count($GLOBALS['odds_comparison_test_http_requests']);
        try {
            $client->get(self::URL);
            self::fail('expected HttpException');
        } catch (HttpException $exception) {
            self::assertStringContainsString('circuit open', $exception->getMessage());
        }
        self::assertCount($requests, $GLOBALS['odds_comparison_test_http_requests'], 'no request while open');

        // Half-open probe succeeds and closes the circuit.
        $this->now += 3600;
        self::queue(200, 'ok');
        self::assertSame('ok', $client->get(self::URL)->body);
        self::assertSame(CircuitBreaker::CLOSED, $breaker->state(self::HOST)['state']);
    }

    public function testRetryAfterPausesTheHost(): void
    {
        $breaker = $this->breaker();
        self::queue(503, '', ['Retry-After' => '120']);

        try {
            $this->client($breaker)->get(self::URL);
            self::fail('expected HttpException');
        } catch (HttpException $exception) {
            self::assertSame(120, $exception->retryAfter);
        }

        self::assertFalse($breaker->allowRequest(self::HOST));
        self::assertCount(1, $GLOBALS['odds_comparison_test_http_requests'], 'no retry when paused');
    }

    public function testServerErrorsAreRetriedWithBackoff(): void
    {
        self::queue(502);
        $GLOBALS['odds_comparison_test_http_queue'][] = new WP_Error('http_request_failed', 'timed out');
        self::queue(200, 'ok');

        self::assertSame('ok', $this->client()->get(self::URL)->body);
        self::assertCount(3, $GLOBALS['odds_comparison_test_http_requests']);
        self::assertContains(1.0, $this->sleeps);
        self::assertContains(3.0, $this->sleeps);
    }

    public function testExhaustedRetriesCountAsOneBreakerFailure(): void
    {
        $breaker = $this->breaker();
        self::queue(500);
        self::queue(500);
        self::queue(500);

        $this->expectException(HttpException::class);

        try {
            $this->client($breaker)->get(self::URL);
        } finally {
            self::assertSame(1, $breaker->state(self::HOST)['failures']);
        }
    }

    public function testRunDeadlineShortensTimeoutsAndCutsRetries(): void
    {
        $breaker = $this->breaker();
        self::queue(500);
        self::queue(500);
        self::queue(200, 'too late');

        try {
            $this->client($breaker, $this->deadline(12.0))->get(self::URL);
            self::fail('Expected the retries to be cut.');
        } catch (HttpException $exception) {
            self::assertStringContainsString('failed after retries', $exception->getMessage());
        }

        // 1st attempt: 12 s left. Retry after 1 s pause + 2.5 s throttle: 8.5 s left. A third
        // attempt would need 3 s pause + 4 s throttle + 5 s, more than is left.
        $requests = $GLOBALS['odds_comparison_test_http_requests'];
        self::assertSame([12, 8], array_column(array_column($requests, 'args'), 'timeout'));
        self::assertSame(1, $breaker->state(self::HOST)['failures'], 'a cut retry is still a failure');
    }

    public function testNoRequestWhenTheRunIsOutOfTime(): void
    {
        $breaker = $this->breaker();
        self::queue(200, 'ok');

        try {
            $this->client($breaker, $this->deadline(4.0))->get(self::URL);
            self::fail('Expected TimeBudgetExceeded.');
        } catch (TimeBudgetExceeded $exception) {
            self::assertStringContainsString('out of time', $exception->getMessage());
        }

        self::assertSame([], $GLOBALS['odds_comparison_test_http_requests']);
        self::assertSame(0, $breaker->state(self::HOST)['failures'], 'not a source failure');
    }

    public function testWithoutARunTheNormalTimeoutApplies(): void
    {
        self::queue(200, 'ok');
        $deadline = $this->deadline(4.0);
        $deadline->clear();

        $this->client(null, $deadline)->get(self::URL);

        self::assertSame(15, $GLOBALS['odds_comparison_test_http_requests'][0]['args']['timeout']);
    }

    public function testCloudflareChallengeIsAFailureAndNeverRetried(): void
    {
        $breaker = $this->breaker();
        self::queue(403, '<html><head><title>Just a moment...</title></head></html>', ['cf-mitigated' => 'challenge']);

        try {
            $this->client($breaker)->get(self::URL);
            self::fail('expected HttpException');
        } catch (HttpException $exception) {
            self::assertStringContainsString('bot protection', $exception->getMessage());
        }

        self::assertCount(1, $GLOBALS['odds_comparison_test_http_requests']);
        self::assertSame(1, $breaker->state(self::HOST)['failures']);
    }

    public function testNotFoundDoesNotCountAgainstTheSource(): void
    {
        $breaker = $this->breaker();
        self::queue(404);

        try {
            $this->client($breaker)->get(self::URL);
            self::fail('expected HttpException');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode);
        }

        self::assertSame(0, $breaker->state(self::HOST)['failures']);
    }

    public function testOversizedBodyIsRejected(): void
    {
        self::queue(200, str_repeat('x', WpHttpClient::MAX_BYTES));

        $this->expectException(HttpException::class);
        $this->client()->get(self::URL);
    }

    public function testApiKeyNeverAppearsInErrors(): void
    {
        self::queue(401);

        try {
            $this->client()->get('https://api.the-odds-api.com/v4/sports/soccer_epl/odds?regions=uk&apiKey=SECRET123');
            self::fail('expected HttpException');
        } catch (HttpException $exception) {
            self::assertStringNotContainsString('SECRET123', $exception->getMessage());
            self::assertStringContainsString('apiKey=REDACTED', $exception->getMessage());
        }
    }

    public function testApiKeyInATransportErrorIsNotStored(): void
    {
        $breaker = $this->breaker();
        $url     = 'https://api.the-odds-api.com/v4/sports/soccer_epl/odds?regions=uk&apiKey=SECRET123';

        foreach (range(1, 3) as $attempt) {
            $GLOBALS['odds_comparison_test_http_queue'][] = new WP_Error('http_request_failed', 'Failed: ' . $url);
        }

        try {
            $this->client($breaker)->get($url);
            self::fail('expected HttpException');
        } catch (HttpException $exception) {
            self::assertStringNotContainsString('SECRET123', $exception->getMessage());
        }

        self::assertStringNotContainsString('SECRET123', $breaker->state('api.the-odds-api.com')['lastError']);
    }

    public function testHttpArgsFilterCannotDropDefaults(): void
    {
        add_filter('odds_comparison/http_args', static fn (array $args): array => ['timeout' => 30]);
        self::queue(200, 'ok');

        $this->client()->get(self::URL);
        $args = $GLOBALS['odds_comparison_test_http_requests'][0]['args'];

        self::assertSame(30, $args['timeout']);
        self::assertTrue($args['reject_unsafe_urls']);
        remove_all_filters('odds_comparison/http_args');
    }

    public function testRecordedClientServesCapturesByUrl(): void
    {
        $fixtures = __DIR__ . '/../../../fixtures';

        if (!is_file($fixtures . '/bettingodds/manifest.json')) {
            self::markTestSkipped('No recorded fixtures.');
        }

        $client   = new RecordedHttpClient($fixtures);
        $response = $client->get(self::URL);

        self::assertSame(200, $response->status);
        self::assertSame(
            hash_file('sha256', $fixtures . '/bettingodds/premier-league-listing.txt'),
            hash('sha256', $response->body)
        );
        self::assertNotNull($client->capturedAt(self::URL));

        if (is_file($fixtures . '/theoddsapi/manifest.json')) {
            $api = $client->get(
                'https://api.the-odds-api.com/v4/sports/soccer_epl/odds'
                . '?regions=uk&markets=h2h&oddsFormat=decimal&apiKey=ANY'
            );
            self::assertStringStartsWith('[', $api->body);
        }

        $this->expectException(HttpException::class);
        $client->get('https://www.bettingodds.com/football/not-recorded');
    }
}
