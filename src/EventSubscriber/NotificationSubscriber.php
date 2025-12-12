<?php

namespace App\EventSubscriber;

use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
class NotificationSubscriber
{
    public function __construct(
        private EmailService $emailService,
        private UserRepository $userRepository,
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

}
