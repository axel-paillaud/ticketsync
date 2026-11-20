<?php

namespace App\Tests\Unit;

use App\Model\MonthlyQuota;
use App\Model\QuotaState;
use App\Twig\QuotaExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A 3h package with the default 30% tolerance: the client is warned beyond 3h54.
 */
class MonthlyQuotaTest extends TestCase
{
    #[DataProvider('consumptionProvider')]
    public function testStateDependsOnPackageAndTolerance(float $consumed, QuotaState $expected): void
    {
        $quota = new MonthlyQuota(3.0, $consumed, 30, new \DateTimeImmutable('2026-11-01'));

        $this->assertSame($expected, $quota->getState());
        $this->assertSame($expected === QuotaState::Exceeded, $quota->isExceeded());
    }

    public static function consumptionProvider(): iterable
    {
        yield 'nothing yet' => [0.0, QuotaState::Ok];
        yield 'half the package' => [1.5, QuotaState::Ok];
        yield 'package exactly used' => [3.0, QuotaState::Ok];
        yield 'a quarter over' => [3.25, QuotaState::Tolerated];
        yield 'right at the tolerance limit' => [3.9, QuotaState::Tolerated];
        yield 'just beyond the tolerance' => [4.0, QuotaState::Exceeded];
    }

    public function testZeroToleranceWarnsAsSoonAsThePackageIsExceeded(): void
    {
        $quota = new MonthlyQuota(3.0, 3.25, 0, new \DateTimeImmutable('2026-11-01'));

        $this->assertSame(QuotaState::Exceeded, $quota->getState());
    }

    public function testNextMonthStartsOnTheFirst(): void
    {
        $quota = new MonthlyQuota(3.0, 0.0, 30, new \DateTimeImmutable('2026-12-01'));

        $this->assertSame('2027-01-01', $quota->getNextMonthStart()->format('Y-m-d'));
    }

    #[DataProvider('hoursProvider')]
    public function testFormatHours(float|string $hours, string $expected): void
    {
        $this->assertSame($expected, QuotaExtension::formatHours($hours));
    }

    public static function hoursProvider(): iterable
    {
        yield [0, '0h'];
        yield [0.5, '0h30'];
        yield ['2.25', '2h15'];
        yield [3, '3h'];
        yield ['3.90', '3h54'];
    }
}
