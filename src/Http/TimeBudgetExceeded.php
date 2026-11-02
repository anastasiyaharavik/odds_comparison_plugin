<?php

/**
 * A request not made because the refresh run is out of time.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Thrown by the HTTP client before a request that would not finish within the run's deadline.
 * Nothing was sent, so it is not a source failure: the circuit breaker and the error log ignore
 * it, and the item waits for the next run.
 */
final class TimeBudgetExceeded extends RuntimeException
{
}
