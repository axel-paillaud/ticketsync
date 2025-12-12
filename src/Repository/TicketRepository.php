<?php

namespace App\Repository;

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
     * 
     * @return Ticket[] Returns an array of Ticket objects
     */
    public function findByOrganizationOrderedByPriority($organization): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.priority', 'p')
            ->andWhere('t.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('p.level', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
