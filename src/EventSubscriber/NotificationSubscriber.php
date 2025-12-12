<?php

namespace App\EventSubscriber;

use App\Entity\Comment;
use App\Entity\Ticket;
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

    private function notifyTicketCreated(Ticket $ticket): void
    {
        $organization = $ticket->getOrganization();
        $creator = $ticket->getCreatedBy();

        // Get all admins
        $admins = $this->userRepository->findByRole('ROLE_ADMIN');

        // get all users in the organization
        $orgUsers = $organization->getUsers();

        // Merge and deduplicate
        $recipients = [];
        foreach ($admins as $admin) {
            $recipients[$admin->getId()] = $admin;
        }
        foreach ($orgUsers as $user) {
            $recipients[$user->getId()] = $user;
        }

        // Send to everyone except the creator
        foreach ($recipients as $recipient) {
            if ($recipient->getId() !== $creator->getId()) {
                $this->emailService->sendTicketCreatedNotification($ticket, $recipient);
            }
        }
    }

    private function notifyCommentAdded(Comment $comment): void
    {
        $ticket = $comment->getTicket();
        $organization = $ticket->getOrganization();
        $author = $comment->getAuthor();

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

        // Send to everyone except the comment author
        foreach ($recipients as $recipient) {
            if ($recipient->getId() !== $author->getId()) {
                $this->emailService->sendCommentAddedNotification($comment, $recipient);
            }
        }
    }
}
