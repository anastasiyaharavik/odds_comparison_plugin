<?php

/**
 * Tests for RobotsTxt.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Http;

use OddsComparison\Http\RobotsTxt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    /** Rules in the shape of BettingOdds.com's robots.txt (2026-10-30), plus a specific group. */
    private const ROBOTS = <<<'TXT'
        User-Agent: *
        Disallow: /images/silks/2023*.png$
        Disallow: /admin
        Disallow: /admin/*
        Disallow: */out.php?*
        Disallow: /ajax/*
        Disallow: /out-us/*
        Allow: /ajax/public-odds   # comment

        User-agent: SomeOtherBot
        Disallow: /
        TXT;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'competition listing'   => ['/football/premier-league', true],
            'outright page'         => ['/football/premier-league/winner', true],
            'match page'            => ['/football/chelsea-man-utd', true],
            'ajax endpoint'         => ['/ajax/odds?id=1', false],
            'longer allow wins'     => ['/ajax/public-odds', true],
            'redirector anywhere'   => ['/football/out.php?url=165', false],
            'root redirector'       => ['/out.php?lt=odc&url=165', false],
            'admin prefix'          => ['/administrator', false],
            'anchored image match'  => ['/images/silks/2023-01.png', false],
            'anchor not at end'     => ['/images/silks/2023-01.png?x=1', true],
        ];
    }

    #[DataProvider('paths')]
    public function testWildcardGroup(string $path, bool $allowed): void
    {
        self::assertSame($allowed, (new RobotsTxt(self::ROBOTS, 'OddsComparisonPlugin'))->isAllowed($path));
    }

    public function testSpecificGroupOverridesWildcard(): void
    {
        $robots = new RobotsTxt(self::ROBOTS, 'SomeOtherBot');

        self::assertFalse($robots->isAllowed('/football/premier-league'));
    }

    public function testEmptyDisallowAndMissingFileAllowEverything(): void
    {
        self::assertTrue((new RobotsTxt("User-agent: *\nDisallow:\n", 'X'))->isAllowed('/anything'));
        self::assertTrue((new RobotsTxt('', 'X'))->isAllowed('/anything'));
    }

    public function testEqualLengthTieGoesToAllow(): void
    {
        $robots = new RobotsTxt("User-agent: *\nDisallow: /page\nAllow: /page\n", 'X');

        self::assertTrue($robots->isAllowed('/page'));
    }
}
