<?php

/**
 * Outcome of validating fetched odds.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds;

use OddsComparison\Domain\MarketOdds;

defined('ABSPATH') || exit;

/**
 * Either accepted (cleaned) odds or a rejection reason, plus notes about dropped prices.
 */
final class ValidationResult
{
    /**
     * @param MarketOdds|null $odds      Cleaned odds when accepted, null when rejected.
     * @param string|null     $rejection Why the odds were rejected, null when accepted.
     * @param list<string>    $notes     What was dropped and why (for the error log / status).
     */
    private function __construct(
        public readonly ?MarketOdds $odds,
        public readonly ?string $rejection,
        public readonly array $notes
    ) {
    }

    /**
     * Accepted result.
     *
     * @param MarketOdds   $odds  Cleaned odds.
     * @param list<string> $notes Drop notes.
     * @return self
     */
    public static function accepted(MarketOdds $odds, array $notes): self
    {
        return new self($odds, null, $notes);
    }

    /**
     * Rejected result; the caller keeps the previous good data.
     *
     * @param string       $reason Rejection reason.
     * @param list<string> $notes  Drop notes.
     * @return self
     */
    public static function rejected(string $reason, array $notes): self
    {
        return new self(null, $reason, $notes);
    }

    /**
     * Whether the odds passed validation.
     *
     * @return bool
     */
    public function isAccepted(): bool
    {
        return $this->odds !== null;
    }
}
