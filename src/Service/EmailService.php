<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Status;
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
             ->subject(sprintf('[TicketSync] New ticket #%d', $ticket->getId()))
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
              ->subject(sprintf('[TicketSync] New comment on ticket #%d', $comment->getTicket()->getId()))
              ->htmlTemplate('emails/comment_added.html.twig')
              ->context([
                  'comment' => $comment,
                  'ticket' => $comment->getTicket(),
                  'recipient' => $recipient,
              ]);

          $this->mailer->send($email);
      }

      /**
       * Send email when a ticket status is updated
       */
       public function sendStatusChangedNotification(Ticket $ticket, User $recipient, Status $oldStatus, Status $newStatus): void
       {
           $email = (new TemplatedEmail())
               ->from(new Address($this->fromAddress, $this->fromName))
               ->to($recipient->getEmail())
               ->subject(sprintf('[TicketSync] Ticket #%d status updated', $ticket->getId()))
               ->htmlTemplate('emails/status_changed.html.twig')
               ->context([
                   'ticket' => $ticket,
                   'recipient' => $recipient,
                   'oldStatus' => $oldStatus,
                   'newStatus' => $newStatus,
               ]);

           $this->mailer->send($email);
       }
}
