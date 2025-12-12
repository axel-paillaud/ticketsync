<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * Find tickets by organization, ordered by priority level (DESC) then creation date (DESC)
     * Resolved tickets are excluded by default or placed at the end if included
     * 
     * @param Organization $organization The organization to filter by
     * @param bool $includeResolved Whether to include resolved tickets (placed at the end)
     * @return Ticket[] Returns an array of Ticket objects
     */
    public function findByOrganizationOrderedByPriority(Organization $organization, bool $includeResolved = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.priority', 'p')
            ->leftJoin('t.status', 's')
            ->andWhere('t.organization = :organization')
            ->setParameter('organization', $organization);

        if (!$includeResolved) {
            // Exclude resolved tickets
            $qb->andWhere('s.slug != :resolvedSlug')
               ->setParameter('resolvedSlug', 'resolved');
        }

        // Order by: resolved status (non-resolved first), then priority, then creation date
        $qb->addSelect('CASE WHEN s.slug = :resolvedSlugOrder THEN 1 ELSE 0 END as HIDDEN is_resolved')
           ->setParameter('resolvedSlugOrder', 'resolved')
           ->orderBy('is_resolved', 'ASC')
           ->addOrderBy('p.level', 'DESC')
           ->addOrderBy('t.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find all tickets (admin view), ordered by priority level (DESC) then creation date (DESC)
     * Resolved tickets are excluded by default or placed at the end if included
     * 
     * @param bool $includeResolved Whether to include resolved tickets (placed at the end)
     * @return Ticket[] Returns an array of Ticket objects
     */
    public function findAllOrderedByPriority(bool $includeResolved = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.priority', 'p')
            ->leftJoin('t.status', 's');

        if (!$includeResolved) {
            // Exclude resolved tickets
            $qb->andWhere('s.slug != :resolvedSlug')
               ->setParameter('resolvedSlug', 'resolved');
        }

        // Order by: resolved status (non-resolved first), then priority, then creation date
        $qb->addSelect('CASE WHEN s.slug = :resolvedSlugOrder THEN 1 ELSE 0 END as HIDDEN is_resolved')
           ->setParameter('resolvedSlugOrder', 'resolved')
           ->orderBy('is_resolved', 'ASC')
           ->addOrderBy('p.level', 'DESC')
           ->addOrderBy('t.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
