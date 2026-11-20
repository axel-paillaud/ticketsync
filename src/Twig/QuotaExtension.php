<?php

namespace App\Twig;

use App\Entity\Organization;
use App\Model\MonthlyQuota;
use App\Service\MonthlyQuotaCalculator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class QuotaExtension extends AbstractExtension
{
    public function __construct(
        private readonly MonthlyQuotaCalculator $calculator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('monthly_quota', [$this, 'getMonthlyQuota']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_hours', [self::class, 'formatHours']),
        ];
    }

    public function getMonthlyQuota(?Organization $organization): ?MonthlyQuota
    {
        return $organization ? $this->calculator->forCurrentMonth($organization) : null;
    }

    /**
     * 2.25 => "2h15", 3 => "3h", 0.5 => "0h30".
     */
    public static function formatHours(float|string|null $hours): string
    {
        $minutes = (int) round((float) $hours * 60);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? sprintf('%dh', $h) : sprintf('%dh%02d', $h, $m);
    }
}
