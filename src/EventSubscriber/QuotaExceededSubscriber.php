<?php

namespace App\EventSubscriber;

use App\Entity\Activity;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\MonthlyQuotaCalculator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Emails the organization (and admins) once per month, when the hours logged
 * this month go beyond the monthly hours tolerance.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
class QuotaExceededSubscriber
{
    /** @var array<int, Organization> organizations whose current month changed */
    private array $pending = [];

    public function __construct(
        private readonly MonthlyQuotaCalculator $calculator,
        private readonly EmailService $emailService,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->track($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->track($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->pending)) {
            return;
        }

        // Clear the queue before flushing to avoid re-entering this method
        $organizations = $this->pending;
        $this->pending = [];

        $now = new \DateTimeImmutable();
        $month = $now->format('Y-m');
        $notified = false;

        foreach ($organizations as $organization) {
            if ($organization->getQuotaExceededNotifiedMonth() === $month) {
                continue;
            }

            $quota = $this->calculator->forMonth($organization, $now);
            if (!$quota?->isExceeded()) {
                continue;
            }

            $organization->setQuotaExceededNotifiedMonth($month);
            $notified = true;

            foreach ($this->getRecipients($organization) as $recipient) {
                $this->emailService->sendQuotaExceededNotification($organization, $quota, $recipient);
            }
        }

        if ($notified) {
            $args->getObjectManager()->flush();
        }
    }

    private function track(object $entity): void
    {
        if (!$entity instanceof Activity) {
            return;
        }

        $organization = $entity->getOrganization();
        if (!$organization?->hasMonthlyHours()) {
            return;
        }

        // Only hours of the current month can trigger the email
        if ($entity->getWorkDate()?->format('Y-m') !== (new \DateTimeImmutable())->format('Y-m')) {
            return;
        }

        $this->pending[$organization->getId()] = $organization;
    }

    /**
     * @return User[] organization members and admins, deduplicated
     */
    private function getRecipients(Organization $organization): array
    {
        $recipients = [];
        foreach ($this->userRepository->findByRole('ROLE_ADMIN') as $admin) {
            $recipients[$admin->getId()] = $admin;
        }
        foreach ($this->userRepository->findBy(['organization' => $organization]) as $user) {
            $recipients[$user->getId()] = $user;
        }

        return $recipients;
    }
}
