<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromAddress,
        private string $fromName,
    ) {}

    /**
     * Send a simple text email when a ticket is created
     */
     public function sendTicketCreatedNotification(Ticket $ticket, User $recipient): void
     {
         $body = sprintf(
             "Bonjour %s,\n\n" .
             "Un nouveau ticket a été créé :\n\n" .
             "Ticket #%d\n" .
             "Titre : %s\n" .
             "Organisation : %s\n" .
             "Créé par : %s\n" .
             "Statut : %s\n" .
             "Priorité : %s\n\n" .
             "Description :\n%s\n\n" .
             "---\n" .
             "Ceci est un email automatique de TicketSync.",
             $recipient->getName(),
             $ticket->getId(),
             $ticket->getTitle(),
             $ticket->getOrganization()->getName(),
             $ticket->getCreatedBy()->getName(),
             $ticket->getStatus()->getName(),
             $ticket->getPriority()->getName(),
             $ticket->getDescription()
         );

         $email = (new Email())
            ->from($this->fromAddress)
            ->to($recipient->getEmail())
            ->subject(sprintf('[TicketSync] Nouveau ticket #%d', $ticket->getId()))
            ->text($body);

         $this->mailer->send($email);
     }
}
