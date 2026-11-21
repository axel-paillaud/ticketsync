<?php

namespace App\Tests\Unit;

use App\Mcp\Tma\ActivityTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Session time is billed by quarter hour, always rounded up.
 */
class ActivityBillingRoundingTest extends TestCase
{
    #[DataProvider('minutesProvider')]
    public function testRoundsUpToTheNextQuarterHour(int $minutes, string $expectedHours): void
    {
        $this->assertSame($expectedHours, ActivityTools::roundUpToBillingStep($minutes));
    }

    public static function minutesProvider(): iterable
    {
        yield 'a short look at a message' => [2, '0.25'];
        yield 'exactly a quarter' => [15, '0.25'];
        yield 'one minute over' => [16, '0.50'];
        yield 'two clocks 0:23 + 0:04' => [27, '0.50'];
        yield 'two clocks 0:23 + 0:12' => [35, '0.75'];
        yield 'one hour' => [60, '1.00'];
        yield 'just over an hour' => [61, '1.25'];
    }
}
