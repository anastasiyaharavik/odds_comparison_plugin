<?php

/**
 * Minimal robots.txt evaluator.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

defined('ABSPATH') || exit;

/**
 * Decides whether a path may be fetched under a robots.txt (RFC 9309 subset).
 *
 * Supported: `User-agent` groups (the group naming our product token wins over `*`), `Allow` and
 * `Disallow` with `*` wildcards and the `$` end anchor, longest-match precedence with `Allow`
 * winning ties, and an empty `Disallow` meaning "allow everything". Unknown lines are ignored.
 * Pure PHP so it can be unit-tested and used by the capture script and the Status tab.
 */
final class RobotsTxt
{
    /**
     * Rules of the applicable group as [allow (bool), pattern (string)] pairs.
     *
     * @var list<array{bool, string}>
     */
    private array $rules;

    /**
     * @param string $contents     robots.txt body.
     * @param string $productToken Our User-Agent product token (e.g. `OddsComparisonPlugin`).
     */
    public function __construct(string $contents, string $productToken)
    {
        $this->rules = $this->selectGroup($this->parseGroups($contents), strtolower($productToken));
    }

    /**
     * Whether a path (with optional query string) may be fetched.
     *
     * @param string $path Host-relative path, e.g. `/football/premier-league`.
     * @return bool
     */
    public function isAllowed(string $path): bool
    {
        $bestLength = -1;
        $allowed    = true;

        foreach ($this->rules as [$allow, $pattern]) {
            if ($pattern === '' || !$this->matches($pattern, $path)) {
                continue;
            }

            $length = strlen($pattern);

            if ($length > $bestLength || ($length === $bestLength && $allow)) {
                $bestLength = $length;
                $allowed    = $allow;
            }
        }

        return $allowed;
    }

    /**
     * Parse the file into groups of user agents and their rules.
     *
     * @param string $contents robots.txt body.
     * @return list<array{agents: list<string>, rules: list<array{bool, string}>}>
     */
    private function parseGroups(string $contents): array
    {
        $groups      = [];
        $current     = null;
        $lastWasRule = false;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if (!str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field           = strtolower($field);

            if ($field === 'user-agent') {
                // Consecutive User-agent lines share one group; a User-agent after rules starts a new one.
                if ($current === null || $lastWasRule) {
                    $groups[]    = ['agents' => [], 'rules' => []];
                    $current     = array_key_last($groups);
                    $lastWasRule = false;
                }

                $groups[$current]['agents'][] = strtolower($value);
            } elseif (($field === 'allow' || $field === 'disallow') && $current !== null) {
                $groups[$current]['rules'][] = [$field === 'allow', $value];
                $lastWasRule                 = true;
            }
        }

        return $groups;
    }

    /**
     * Rules of the group that applies to us: our product token, else `*`, else none.
     *
     * @param list<array{agents: list<string>, rules: list<array{bool, string}>}> $groups       Parsed groups.
     * @param string                                                              $productToken Lower-case token.
     * @return list<array{bool, string}>
     */
    private function selectGroup(array $groups, string $productToken): array
    {
        $specific = [];
        $wildcard = [];

        foreach ($groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent === $productToken) {
                    $specific = array_merge($specific, $group['rules']);
                } elseif ($agent === '*') {
                    $wildcard = array_merge($wildcard, $group['rules']);
                }
            }
        }

        return $specific !== [] ? $specific : $wildcard;
    }

    /**
     * Match a robots pattern (prefix match, `*` wildcard, `$` end anchor) against a path.
     *
     * @param string $pattern Rule pattern.
     * @param string $path    Path to test.
     * @return bool
     */
    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern  = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex    = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return preg_match('#^' . $regex . ($anchored ? '$' : '') . '#', $path) === 1;
    }
}
