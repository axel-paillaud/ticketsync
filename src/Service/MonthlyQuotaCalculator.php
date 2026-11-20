<?php

namespace App\Service;

use App\Entity\Organization;
use App\Model\MonthlyQuota;
use App\Repository\ActivityRepository;

class MonthlyQuotaCalculator
{
    /** @var array<int, MonthlyQuota> */
    private array $cache = [];

    public function __construct(
        private readonly ActivityRepository $activityRepository,
    ) {
    }

    /**
     * Quota of the current month, or null when the organization has no
     * monthly package (billed on actual hours, nothing to display).
     */
    public function forCurrentMonth(Organization $organization): ?MonthlyQuota
    {
        if (!$organization->hasMonthlyHours()) {
            return null;
        }

        return $this->cache[$organization->getId()] ??= $this->forMonth($organization, new \DateTimeImmutable());
    }

    public function forMonth(Organization $organization, \DateTimeImmutable $date): ?MonthlyQuota
    {
        if (!$organization->hasMonthlyHours()) {
            return null;
        }

        $monthStart = $date->modify('first day of this month')->setTime(0, 0);
        $consumed = $this->activityRepository->sumHoursForOrganization(
            $organization,
            $monthStart,
            $monthStart->modify('first day of next month'),
        );

        return new MonthlyQuota(
            (float) $organization->getMonthlyHours(),
            $consumed,
            $organization->getOverageTolerance(),
            $monthStart,
        );
    }
}
