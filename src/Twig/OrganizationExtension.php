<?php

namespace App\Twig;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes `currentOrganization`: the organization being viewed.
 */
class OrganizationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private OrganizationRepository $organizationRepository,
    ) {}

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        return [
            'currentOrganization' => $this->getOrganizationFromUrl()
                ?? ($user instanceof User ? $user->getOrganization() : null),
        ];
    }

    private function getOrganizationFromUrl(): ?Organization
    {
        $slug = $this->requestStack->getMainRequest()?->attributes->get('organizationSlug');
        if (!is_string($slug) || $slug === '') {
            return null;
        }

        $organization = $this->organizationRepository->findOneBy(['slug' => $slug]);

        // Never expose an organization the user cannot access, e.g. on the
        // 403 page a member gets when typing another client's URL.
        if ($organization === null || !$this->security->isGranted('ORGANIZATION_ACCESS', $organization)) {
            return null;
        }

        return $organization;
    }
}
