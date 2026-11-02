<?php

/**
 * Event value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * An event discovered on a source: a match (e.g. Chelsea v Manchester United) or a
 * competition's outrights (one per competition, for markets like Outright Winner).
 *
 * The id is source-scoped and stable: `{sourceId}:{localId}`, e.g.
 * `bettingodds:premier-league/chelsea-man-utd` or `bettingodds:premier-league/outrights`.
 * `path` is the event page relative to the source host. Scraped paths are validated here, so
 * an event can never point at another host (SSRF guard).
 *
 * A match always has home, away and a start time. An outrights event has none of them and
 * never counts as started.
 */
final class Event
{
    /** A single fixture between two participants. */
    public const KIND_MATCH = 'match';

    /** A competition's outright (season-long) markets. */
    public const KIND_OUTRIGHT = 'outright';

    /** Local id suffix of every outrights event. */
    public const OUTRIGHTS_LOCAL_SUFFIX = 'outrights';

    /** Allowed characters of the source-local part of an id. */
    private const LOCAL_ID_PATTERN = '#^[A-Za-z0-9._~/-]+$#';

    /** Kick-off time in UTC; null for outrights. */
    public readonly ?DateTimeImmutable $startsAt;

    /**
     * @param string                 $id          Source-scoped id (`{sourceId}:{localId}`).
     * @param string                 $sourceId    Source id (e.g. `bettingodds`).
     * @param string                 $competition Competition slug.
     * @param string                 $kind        KIND_MATCH or KIND_OUTRIGHT.
     * @param string                 $name        Display name (e.g. "Chelsea vs Manchester United").
     * @param string|null            $home        Home participant (matches only).
     * @param string|null            $away        Away participant (matches only).
     * @param DateTimeImmutable|null $startsAt    Kick-off time, converted to UTC (matches only).
     * @param string                 $path        Event page path relative to the source host (leading slash).
     * @throws InvalidArgumentException When any field is invalid or inconsistent with the kind.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sourceId,
        public readonly string $competition,
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $home,
        public readonly ?string $away,
        ?DateTimeImmutable $startsAt,
        public readonly string $path
    ) {
        Assert::slug($sourceId, 'Event sourceId');
        self::assertId($id, $sourceId);
        Assert::slug($competition, 'Event competition');
        Assert::notBlank($name, 'Event name');
        Assert::relativePath($path, 'Event path', true);

        if ($kind === self::KIND_MATCH) {
            if ($home === null || $away === null || $startsAt === null) {
                throw new InvalidArgumentException('A match event needs home, away and startsAt.');
            }

            Assert::notBlank($home, 'Event home');
            Assert::notBlank($away, 'Event away');
        } elseif ($kind === self::KIND_OUTRIGHT) {
            if ($home !== null || $away !== null || $startsAt !== null) {
                throw new InvalidArgumentException('An outrights event has no home, away or startsAt.');
            }
        } else {
            throw new InvalidArgumentException(sprintf('Unknown event kind %s.', json_encode($kind, Assert::JSON)));
        }

        $this->startsAt = $startsAt?->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Create a match event.
     *
     * @param string            $sourceId    Source id.
     * @param string            $localId     Id unique within the source (e.g. `premier-league/chelsea-man-utd`).
     * @param string            $competition Competition slug.
     * @param string            $name        Display name.
     * @param string            $home        Home participant.
     * @param string            $away        Away participant.
     * @param DateTimeImmutable $startsAt    Kick-off time.
     * @param string            $path        Host-relative event page path.
     * @return self
     * @throws InvalidArgumentException When any field is invalid.
     */
    public static function match(
        string $sourceId,
        string $localId,
        string $competition,
        string $name,
        string $home,
        string $away,
        DateTimeImmutable $startsAt,
        string $path
    ): self {
        return new self(
            self::makeId($sourceId, $localId),
            $sourceId,
            $competition,
            self::KIND_MATCH,
            $name,
            $home,
            $away,
            $startsAt,
            $path
        );
    }

    /**
     * Create the outrights event of a competition.
     *
     * @param string $sourceId    Source id.
     * @param string $competition Competition slug.
     * @param string $name        Display name (e.g. "Premier League — Outrights").
     * @param string $path        Host-relative competition listing path.
     * @return self
     * @throws InvalidArgumentException When any field is invalid.
     */
    public static function outrights(string $sourceId, string $competition, string $name, string $path): self
    {
        return new self(
            self::makeId($sourceId, $competition . '/' . self::OUTRIGHTS_LOCAL_SUFFIX),
            $sourceId,
            $competition,
            self::KIND_OUTRIGHT,
            $name,
            null,
            null,
            null,
            $path
        );
    }

    /**
     * Build a source-scoped event id.
     *
     * @param string $sourceId Source id.
     * @param string $localId  Id unique within the source.
     * @return string
     */
    public static function makeId(string $sourceId, string $localId): string
    {
        return $sourceId . ':' . $localId;
    }

    /**
     * Rebuild from {@see self::toArray()} output (event index storage).
     *
     * @param array<string, mixed> $data Stored data.
     * @return self
     * @throws InvalidArgumentException When the data is malformed.
     */
    public static function fromArray(array $data): self
    {
        $startsAt = $data['startsAt'] ?? null;

        return new self(
            Field::string($data, 'id'),
            Field::string($data, 'sourceId'),
            Field::string($data, 'competition'),
            Field::string($data, 'kind'),
            Field::string($data, 'name'),
            self::nullableString($data, 'home'),
            self::nullableString($data, 'away'),
            $startsAt === null ? null : new DateTimeImmutable('@' . Field::int($data, 'startsAt')),
            Field::string($data, 'path')
        );
    }

    /**
     * Plain-array form for storage (`startsAt` as a Unix timestamp or null).
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'sourceId'    => $this->sourceId,
            'competition' => $this->competition,
            'kind'        => $this->kind,
            'name'        => $this->name,
            'home'        => $this->home,
            'away'        => $this->away,
            'startsAt'    => $this->startsAt?->getTimestamp(),
            'path'        => $this->path,
        ];
    }

    /**
     * Whether this is an outrights event.
     *
     * @return bool
     */
    public function isOutright(): bool
    {
        return $this->kind === self::KIND_OUTRIGHT;
    }

    /**
     * Whether a match has kicked off at the given moment. Outrights never count as started.
     *
     * @param DateTimeInterface $now Reference time.
     * @return bool
     */
    public function hasStarted(DateTimeInterface $now): bool
    {
        return $this->startsAt !== null && $this->startsAt <= $now;
    }

    /**
     * Read an optional string field.
     *
     * @param array<string, mixed> $data Stored data.
     * @param string               $key  Key.
     * @return string|null
     * @throws InvalidArgumentException When present but not a string.
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Field %s must be a string or null.', json_encode($key, Assert::JSON))
            );
        }

        return $value;
    }

    /**
     * Validate the id format and its source prefix.
     *
     * @param string $id       Event id.
     * @param string $sourceId Expected source id.
     * @return void
     * @throws InvalidArgumentException When the id is malformed or scoped to another source.
     */
    private static function assertId(string $id, string $sourceId): void
    {
        $prefix = $sourceId . ':';

        if (
            !str_starts_with($id, $prefix)
            || preg_match(self::LOCAL_ID_PATTERN, substr($id, strlen($prefix))) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Event id must look like %s, got %s.',
                    json_encode($prefix . '<local-id>', Assert::JSON),
                    json_encode($id, Assert::JSON)
                )
            );
        }
    }
}
