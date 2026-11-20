<?php

namespace App\Model;

/**
 * Hours consumed by an organization in a given month, against its package.
 */
final readonly class MonthlyQuota
{
    public function __construct(
        public float $monthlyHours,
        public float $consumedHours,
        public int $tolerancePercent,
        public \DateTimeImmutable $monthStart,
    ) {
    }

    /** Hours the organization can reach before the client is warned. */
    public function getToleranceLimit(): float
    {
        return round($this->monthlyHours * (100 + $this->tolerancePercent) / 100, 2);
    }

    public function getState(): QuotaState
    {
        // Compare in hundredths of an hour to avoid float drift (e.g. 3 * 1.3).
        $consumed = (int) round($this->consumedHours * 100);

        if ($consumed <= (int) round($this->monthlyHours * 100)) {
            return QuotaState::Ok;
        }

        if ($consumed <= (int) round($this->getToleranceLimit() * 100)) {
            return QuotaState::Tolerated;
        }

        return QuotaState::Exceeded;
    }

    public function isExceeded(): bool
    {
        return $this->getState() === QuotaState::Exceeded;
    }

    /** Consumption in percent of the package (can go over 100). */
    public function getPercent(): float
    {
        return $this->consumedHours / $this->monthlyHours * 100;
    }

    public function getNextMonthStart(): \DateTimeImmutable
    {
        return $this->monthStart->modify('first day of next month');
    }
}
