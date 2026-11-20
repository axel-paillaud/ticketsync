<?php

namespace App\Security\Voter;

use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class TicketVoter extends Voter
{
    public const EDIT = 'TICKET_EDIT';
    public const DELETE = 'TICKET_DELETE';
    public const RESOLVE = 'TICKET_RESOLVE';

    public function __construct(private Security $security)
    {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::RESOLVE])
            && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // if the user is anonymous, do not grant access
        if (!$user instanceof User) {
            return false;
        }

        /** @var Ticket $ticket */
        $ticket = $subject;

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        if ($user->getOrganization() !== $ticket->getOrganization()) {
            return false;
        }

        // Once a ticket has been worked on (comments or logged activities),
        // only admins may delete it, so its history is kept. Members can still
        // delete an unused ticket created by mistake, or resolve it instead.
        if ($attribute === self::DELETE) {
            return $ticket->getComments()->isEmpty() && $ticket->getActivities()->isEmpty();
        }

        return true;
    }
}
