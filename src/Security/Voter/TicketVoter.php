<?php

namespace App\Security\Voter;

use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class TicketVoter extends Voter
{
    public const EDIT = 'TICKET_EDIT';
    public const DELETE = 'TICKET_DELETE';

    public function __construct(private Security $security)
    {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        // replace with your own logic
        // https://symfony.com/doc/current/security/voters.html
        return in_array($attribute, [self::EDIT, self::DELETE])
            && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
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

        // For now, all users in the same organization can edit/delete
        // You can add more specific logic here if needed
        return $user->getOrganization() === $ticket->getOrganization();
    }
}
