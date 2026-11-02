<?php

/**
 * Recorded-response transport (Demo mode, tests).
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use DateTimeImmutable;
use Exception;
use OddsComparison\Contracts\HttpClientInterface;

defined('ABSPATH') || exit;

/**
 * Serves real responses captured earlier from the sources, looked up by URL through each
 * `fixtures/{source}/manifest.json`. Never touches the network.
 *
 * URLs are compared with `apiKey` redacted, matching how the manifests store them. A URL that
 * was not recorded fails like a 404, so Demo mode can never fall back to live requests.
 */
final class RecordedHttpClient implements HttpClientInterface
{
    /**
     * Redacted URL => [absolute file path, capture time], built on first use.
     *
     * @var array<string, array{string, string}>|null
     */
    private ?array $index = null;

    /**
     * @param string $fixturesDir Absolute path of the fixtures/ directory.
     */
    public function __construct(private readonly string $fixturesDir)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $url): HttpResponse
    {
        $entry = $this->index()[WpHttpClient::redact($url)] ?? null;

        if ($entry === null || !is_readable($entry[0])) {
            throw new HttpException(
                sprintf('No recorded response for %s.', esc_url_raw(WpHttpClient::redact($url))),
                404
            );
        }

        // Local file inside the plugin's fixtures directory, not a remote URL.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $body = (string) file_get_contents($entry[0]);

        return new HttpResponse(200, $body, ['x-recorded-at' => $entry[1]]);
    }

    /**
     * When the response for a URL was captured, or null when it is not recorded.
     *
     * Demo mode resolves recorded kick-off dates against it.
     *
     * @param string $url URL.
     * @return DateTimeImmutable|null
     */
    public function capturedAt(string $url): ?DateTimeImmutable
    {
        $entry = $this->index()[WpHttpClient::redact($url)] ?? null;

        if ($entry === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($entry[1]);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Earliest capture time across all recorded sources, or null when nothing is recorded.
     *
     * @return DateTimeImmutable|null
     */
    public function firstCapturedAt(): ?DateTimeImmutable
    {
        $times = array_filter(array_column($this->index(), 1));

        if ($times === []) {
            return null;
        }

        sort($times);

        try {
            return new DateTimeImmutable((string) reset($times));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Load every manifest once.
     *
     * @return array<string, array{string, string}>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];

        foreach (glob($this->fixturesDir . '/*/manifest.json') ?: [] as $manifestFile) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $manifest = json_decode((string) file_get_contents($manifestFile), true);

            if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
                continue;
            }

            foreach ($manifest['files'] as $file) {
                if (is_array($file) && isset($file['url'], $file['file'])) {
                    $this->index[(string) $file['url']] = [
                        dirname($manifestFile) . '/' . basename((string) $file['file']),
                        (string) ($manifest['capturedAt'] ?? ''),
                    ];
                }
            }
        }

        return $this->index;
    }
}
