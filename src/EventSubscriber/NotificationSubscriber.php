<?php

namespace App\EventSubscriber;

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

        // Only handle Ticket creation for now
        if ($entity instanceof Ticket) {
            $this->notifyTicketCreated($entity);
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
}
