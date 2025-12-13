<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Status;
use App\Entity\Ticket;
use App\Entity\User;
use App\Entity\UserInvitation;
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

       /**
        * Send email when monthly threshold is exceeded
        */
        public function sendThresholdExceededAlert(User $recipient, Organization $organization, float $currentTotal, float $threshold): void
        {
            $email = (new TemplatedEmail())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($recipient->getEmail())
                ->subject(sprintf('[TicketSync] Monthly threshold exceeded for %s', $organization->getName()))
                ->htmlTemplate('emails/threshold_exceeded.html.twig')
                ->context([
                    'recipient' => $recipient,
                    'organization' => $organization,
                    'currentTotal' => $currentTotal,
                    'threshold' => $threshold,
                ]);

            $this->mailer->send($email);
        }

        /**
         * Send invitation email to a new user
         */
        public function sendUserInvitation(UserInvitation $invitation, string $invitationUrl): void
        {
            $user = $invitation->getUser();

            $email = (new TemplatedEmail())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($user->getEmail())
                ->subject('[TicketSync] You have been invited to join TicketSync')
                ->htmlTemplate('emails/user_invitation.html.twig')
                ->context([
                    'user' => $user,
                    'invitation' => $invitation,
                    'invitationUrl' => $invitationUrl,
                ]);

            $this->mailer->send($email);
        }
}
