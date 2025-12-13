<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\Ticket;
use App\Entity\TimeEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeEntry>
 */
class TimeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeEntry::class);
    }

    /**
     * Find time entries by ticket, ordered by work date DESC
     *
     * @param Ticket $ticket
     * @return TimeEntry[]
     */
    public function findByTicket(Ticket $ticket): array
    {
        return $this->createQueryBuilder('te')
            ->andWhere('te.ticket = :ticket')
            ->setParameter('ticket', $ticket)
            ->orderBy('te.workDate', 'DESC')
            ->addOrderBy('te.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find time entries by organization, ordered by work date DESC
     * For admin global view
     *
     * @param Organization $organization
     * @return TimeEntry[]
     */
    public function findByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('te')
            ->leftJoin('te.ticket', 't')
            ->leftJoin('te.createdBy', 'u')
            ->andWhere('te.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('te.workDate', 'DESC')
            ->addOrderBy('te.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all time entries across all organizations (admin only)
     * Ordered by work date DESC
     *
     * @return TimeEntry[]
     */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('te')
            ->leftJoin('te.ticket', 't')
            ->leftJoin('te.organization', 'o')
            ->leftJoin('te.createdBy', 'u')
            ->orderBy('te.workDate', 'DESC')
            ->addOrderBy('te.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find time entries by date range
     *
     * @param \DateTimeImmutable|null $startDate
     * @param \DateTimeImmutable|null $endDate
     * @param Organization|null $organization Filter by organization (null for all)
     * @return TimeEntry[]
     */
    public function findByDateRange(
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?Organization $organization = null
    ): array {
        $qb = $this->createQueryBuilder('te')
            ->leftJoin('te.ticket', 't')
            ->leftJoin('te.createdBy', 'u');

        if ($startDate) {
            $qb->andWhere('te.workDate >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('te.workDate <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        if ($organization) {
            $qb->andWhere('te.organization = :organization')
               ->setParameter('organization', $organization);
        }

        return $qb->orderBy('te.workDate', 'DESC')
                  ->addOrderBy('te.createdAt', 'DESC')
                  ->getQuery()
                  ->getResult();
    }

    /**
     * Calculate total billed amount for an organization in a specific month
     *
     * @param Organization $organization
     * @param int|null $year Default to current year
     * @param int|null $month Default to current month
     * @return float
     */
    public function calculateMonthlyTotal(Organization $organization, ?int $year = null, ?int $month = null): float
    {
        $year = $year ?? (int) date('Y');
        $month = $month ?? (int) date('m');

        $startDate = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $endDate = $startDate->modify('last day of this month');

        $result = $this->createQueryBuilder('te')
            ->select('SUM(te.billedAmount) as total')
            ->andWhere('te.organization = :organization')
            ->andWhere('te.workDate >= :startDate')
            ->andWhere('te.workDate <= :endDate')
            ->setParameter('organization', $organization)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }
}
