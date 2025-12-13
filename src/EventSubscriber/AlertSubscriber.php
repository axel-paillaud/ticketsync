<?php

namespace App\EventSubscriber;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\TimeEntryRepository;
use App\Service\EmailService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
class AlertSubscriber
{
    public function __construct(
        private EmailService $emailService,
        private TimeEntryRepository $timeEntryRepository,
    ) {}

    /**
     * Called after a TimeEntry is created
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof TimeEntry) {
            $this->checkThresholdExceeded($entity, isNew: true);
        }
    }

    /**
     * Called after a TimeEntry is updated
     */
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof TimeEntry) {
            $entityManager = $args->getObjectManager();
            $uow = $entityManager->getUnitOfWork();
            $changeSet = $uow->getEntityChangeSet($entity);

            // Only check if billedAmount changed
            if (isset($changeSet['billedAmount'])) {
                $oldBilledAmount = $changeSet['billedAmount'][0];
                $this->checkThresholdExceeded($entity, isNew: false, oldBilledAmount: $oldBilledAmount);
            }
        }
    }

    /**
     * Check if monthly threshold has been exceeded and send alert
     */
    private function checkThresholdExceeded(TimeEntry $timeEntry, bool $isNew, ?float $oldBilledAmount = null): void
    {
        $organization = $timeEntry->getOrganization();

        // Get all users in the organization with alert enabled
        $usersWithAlert = $organization->getUsers()->filter(function (User $user) {
            return $user->isAlertThresholdEnabled() && $user->getMonthlyAlertThreshold() !== null;
        });

        if ($usersWithAlert->isEmpty()) {
            return;
        }

        // Calculate current monthly total (with the new/updated entry)
        $currentTotal = $this->timeEntryRepository->calculateMonthlyTotal($organization);

        // Calculate previous total (before this entry was added/updated)
        if ($isNew) {
            // For new entry: subtract the new amount
            $previousTotal = $currentTotal - $timeEntry->getBilledAmount();
        } else {
            // For updated entry: subtract new amount and add old amount
            $previousTotal = $currentTotal - $timeEntry->getBilledAmount() + ($oldBilledAmount ?? 0);
        }

        // Check each user's threshold
        foreach ($usersWithAlert as $user) {
            $threshold = $user->getMonthlyAlertThreshold();

            // Alert only if we just exceeded the threshold
            if ($previousTotal < $threshold && $currentTotal >= $threshold) {
                $this->emailService->sendThresholdExceededAlert($user, $organization, $currentTotal, $threshold);
            }
        }
    }
}
