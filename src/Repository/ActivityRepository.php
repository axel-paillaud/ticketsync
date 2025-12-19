<?php

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\Organization;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * Find activities by ticket, ordered by work date DESC
     *
     * @param Ticket $ticket
     * @return Activity[]
     */
    public function findByTicket(Ticket $ticket): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.ticket = :ticket')
            ->setParameter('ticket', $ticket)
            ->orderBy('a.workDate', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find activities by organization, ordered by work date DESC
     * For admin global view
     *
     * @param Organization $organization
     * @return Activity[]
     */
    public function findByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.ticket', 't')
            ->leftJoin('a.createdBy', 'u')
            ->andWhere('a.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('a.workDate', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all activities across all organizations (admin only)
     * Ordered by work date DESC
     *
     * @return Activity[]
     */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.ticket', 't')
            ->leftJoin('a.organization', 'o')
            ->leftJoin('a.createdBy', 'u')
            ->orderBy('a.workDate', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find activities by date range
     *
     * @param \DateTimeImmutable|null $startDate
     * @param \DateTimeImmutable|null $endDate
     * @param Organization|null $organization Filter by organization (null for all)
     * @return Activity[]
     */
    public function findByDateRange(
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?Organization $organization = null
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.ticket', 't')
            ->leftJoin('a.createdBy', 'u');

        if ($startDate) {
            $qb->andWhere('a.workDate >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('a.workDate <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        if ($organization) {
            $qb->andWhere('a.organization = :organization')
               ->setParameter('organization', $organization);
        }

        return $qb->orderBy('a.workDate', 'DESC')
                  ->addOrderBy('a.createdAt', 'DESC')
                  ->getQuery()
                  ->getResult();
    }
}
