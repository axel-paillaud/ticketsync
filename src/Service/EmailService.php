<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromAddress,
        private string $fromName,
    ) {}

    /**
     * Send email when a new ticket is created
     */
     public function sendTicketCreatedNotification(Ticket $ticket, User $recipient): void
     {
         $email = (new TemplatedEmail())
             ->from(new Address($this->fromAddress, $this->fromName))
             ->to($recipient->getEmail())
             ->subject(sprintf('[TicketSync] Nouveau ticket #%d', $ticket->getId()))
             ->htmlTemplate('emails/ticket_created.html.twig')
             ->context([
                 'ticket' => $ticket,
                 'recipient' => $recipient,
             ]);

         $this->mailer->send($email);
     }

     /**
      * Send email when a new comment is added
      */
      public function sendCommentAddedNotification(Comment $comment, User $recipient): void
      {
          $email = (new TemplatedEmail())
              ->from(new Address($this->fromAddress, $this->fromName))
              ->to($recipient->getEmail())
              ->subject(sprintf('[TicketSync] Nouveau commentaire sur ticket #%d', $comment->getTicket()->getId()))
              ->htmlTemplate('emails/comment_added.html.twig')
              ->context([
                  'comment' => $comment,
                  'ticket' => $comment->getTicket(),
                  'recipient' => $recipient,
              ]);

          $this->mailer->send($email);
      }
}
