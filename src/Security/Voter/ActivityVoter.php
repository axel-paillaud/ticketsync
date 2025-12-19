<?php

namespace App\Security\Voter;

use App\Entity\Activity;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class ActivityVoter extends Voter
{
    public const EDIT = 'ACTIVITY_EDIT';
    public const DELETE = 'ACTIVITY_DELETE';

    public function __construct(
        private Security $security
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE])
            && $subject instanceof Activity;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Activity $activity */
        $activity = $subject;

        // MVP: Admin-only feature
        // Admins can edit/delete all activities
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Future: Allow users to manage their own activities
        // Uncomment when feature expands beyond admin-only:
        // return $activity->getCreatedBy() === $user;

        // For now, only admins have access
        return false;
    }
}
