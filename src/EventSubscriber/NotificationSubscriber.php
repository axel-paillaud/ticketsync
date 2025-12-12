<?php

namespace App\EventSubscriber;

use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Status;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
class NotificationSubscriber
{
    public function __construct(
        private EmailService $emailService,
        private UserRepository $userRepository,
        private Security $security,
    ) {}

    /**
    * Called after an entity is created in database
    */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        // Handle Ticket creation
        if ($entity instanceof Ticket) {
            $this->notifyTicketCreated($entity);
        }

        // Handle Comment creation
        if ($entity instanceof Comment) {
            $this->notifyCommentAdded($entity);
        }
    }

    /**
     * Called after an entity is updated in database
     */
     public function postUpdate(PostUpdateEventArgs $args): void
     {
         $entity = $args->getObject();

         if ($entity instanceof Ticket) {
             $entityManager = $args->getObjectManager();
             $uow = $entityManager->getUnitOfWork();
             $changeSet = $uow->getEntityChangeSet($entity);

             if (isset($changeSet['status'])) {
                 $oldStatus = $changeSet['status'][0];
                 $newStatus = $changeSet['status'][1];

                 $this->notifyStatusChanged($entity, $oldStatus, $newStatus);
             }
         }
     }

    /**
     * Get all recipients for notifications (admins + org users)
     */
    private function getNotificationRecipients(Organization $organization, User $exclude): array
    {
        // Get all admins
        $admins = $this->userRepository->findByRole('ROLE_ADMIN');

        // Get all users in the organization
        $orgUsers = $organization->getUsers();

        // Merge and deduplicate
        $recipients = [];
        foreach ($admins as $admin) {
            $recipients[$admin->getId()] = $admin;
        }
        foreach ($orgUsers as $user) {
            $recipients[$user->getId()] = $user;
        }

        // Remove excluded user
        unset($recipients[$exclude->getId()]);

        return $recipients;
    }

    private function notifyTicketCreated(Ticket $ticket): void
    {
        $recipients = $this->getNotificationRecipients(
            $ticket->getOrganization(),
            $ticket->getCreatedBy()
        );

        foreach ($recipients as $recipient) {
            $this->emailService->sendTicketCreatedNotification($ticket, $recipient);
        }
    }

    private function notifyCommentAdded(Comment $comment): void
    {
        $recipients = $this->getNotificationRecipients(
            $comment->getTicket()->getOrganization(),
            $comment->getAuthor()
        );

        foreach ($recipients as $recipient) {
            $this->emailService->sendCommentAddedNotification($comment, $recipient);
        }
    }

    private function notifyStatusChanged(Ticket $ticket, Status $oldStatus, Status $newStatus):void
    {
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            return;
        }

        $recipients = $this->getNotificationRecipients($ticket->getOrganization(), $currentUser);

        foreach ($recipients as $recipient) {
            $this->emailService->sendStatusChangedNotification($ticket, $recipient, $oldStatus, $newStatus);
        }
    }
}
