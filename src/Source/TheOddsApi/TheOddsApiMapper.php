<?php

/**
 * JSON mapper for The Odds API v4 responses.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Source\TheOddsApi;

defined('ABSPATH') || exit;

/**
 * Extracts events and raw prices from `GET /v4/sports/{sport}/odds` responses. Pure PHP.
 *
 * Shape: `[{id, commence_time, home_team, away_team, bookmakers: [{key, markets: [{key,
 * outcomes: [{name, price}]}]}]}]`. Unexpected shapes produce empty results plus warnings,
 * never errors.
 *
 * @phpstan-type RawApiEvent array{id: string, home: string, away: string, commenceTime: string}
 * @phpstan-type RawApiPrice array{bookmaker: string, participant: string, decimal: string}
 */
final class TheOddsApiMapper
{
    /**
     * Diagnostics from the last call.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * Events in a response.
     *
     * @param string $json Response body.
     * @return list<RawApiEvent>
     */
    public function parseEvents(string $json): array
    {
        $this->warnings = [];
        $events         = [];

        foreach ($this->decode($json) as $row) {
            $id = $this->string($row, 'id');

            if ($id === '' || $this->string($row, 'home_team') === '' || $this->string($row, 'away_team') === '') {
                $this->warnings[] = 'Skipped an event without id or teams.';
                continue;
            }

            $events[] = [
                'id'           => $id,
                'home'         => $this->string($row, 'home_team'),
                'away'         => $this->string($row, 'away_team'),
                'commenceTime' => $this->string($row, 'commence_time'),
            ];
        }

        return $events;
    }

    /**
     * Prices of one market of one event.
     *
     * @param string $json      Response body.
     * @param string $eventId   API event id.
     * @param string $marketKey API market key (e.g. `h2h`).
     * @return list<RawApiPrice>
     */
    public function parsePrices(string $json, string $eventId, string $marketKey): array
    {
        $this->warnings = [];
        $prices         = [];

        foreach ($this->decode($json) as $row) {
            if ($this->string($row, 'id') !== $eventId) {
                continue;
            }

            foreach ($this->list($row, 'bookmakers') as $bookmaker) {
                foreach ($this->list($bookmaker, 'markets') as $market) {
                    if ($this->string($market, 'key') !== $marketKey) {
                        continue;
                    }

                    foreach ($this->list($market, 'outcomes') as $outcome) {
                        $price = $outcome['price'] ?? null;

                        $prices[] = [
                            'bookmaker'   => $this->string($bookmaker, 'key'),
                            'participant' => $this->string($outcome, 'name'),
                            'decimal'     => is_int($price) || is_float($price) ? (string) $price : '',
                        ];
                    }
                }
            }
        }

        if ($prices === []) {
            $this->warnings[] = sprintf('No "%s" prices for event %s in the response.', $marketKey, $eventId);
        }

        return $prices;
    }

    /**
     * Diagnostics from the last call.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Decode the top-level event list.
     *
     * @param string $json Body.
     * @return list<array<string, mixed>>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data) || !array_is_list($data)) {
            $this->warnings[] = 'Response is not a JSON list of events.';

            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Array-valued list field.
     *
     * @param array<string, mixed> $row Row.
     * @param string               $key Key.
     * @return list<array<string, mixed>>
     */
    private function list(array $row, string $key): array
    {
        $value = $row[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * Trimmed string field ("" when absent or not a string).
     *
     * @param array<string, mixed> $row Row.
     * @param string               $key Key.
     * @return string
     */
    private function string(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? trim($row[$key]) : '';
    }
}
