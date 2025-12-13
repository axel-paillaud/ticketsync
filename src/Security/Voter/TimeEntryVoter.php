<?php

namespace App\Security\Voter;

use App\Entity\TimeEntry;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class TimeEntryVoter extends Voter
{
    public const EDIT = 'TIMEENTRY_EDIT';
    public const DELETE = 'TIMEENTRY_DELETE';

    public function __construct(
        private Security $security
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE])
            && $subject instanceof TimeEntry;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var TimeEntry $timeEntry */
        $timeEntry = $subject;

        // MVP: Admin-only feature
        // Admins can edit/delete all time entries
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Future: Allow users to manage their own time entries
        // Uncomment when feature expands beyond admin-only:
        // return $timeEntry->getCreatedBy() === $user;

        // For now, only admins have access
        return false;
    }
}
