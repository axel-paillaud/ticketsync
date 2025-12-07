<?php

namespace App\Twig;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class OrganizationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private Security $security
    ) {}

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        if ($user instanceof User && $user->getOrganization()) {
            return [
                'currentOrganization' => $user->getOrganization(),
            ];
        }

        return [
            'currentOrganization' => null,
        ];
    }
}
