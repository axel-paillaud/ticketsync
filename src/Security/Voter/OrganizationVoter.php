<?php

namespace App\Security\Voter;

use App\Entity\Organization;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class OrganizationVoter extends Voter
{
    public const ACCESS = 'ORGANIZATION_ACCESS';

    public function __construct(
        private Security $security
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS && $subject instanceof Organization;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Organization $organization */
        $organization = $subject;

        // Admins can access all organizations
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Regular users must belong to the organization
        return $user->getOrganization() === $organization;
    }
}
