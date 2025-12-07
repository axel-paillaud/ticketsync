<?php

namespace App\Resolver;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrganizationValueResolver implements ValueResolverInterface
{
    public function __construct(
        private OrganizationRepository $organizationRepository
    ) {}

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        // Check if the argument type is Organization
        if ($argument->getType() !== Organization::class) {
            return [];
        }

        // Get the organization slug from the route
        $slug = $request->attributes->get('organizationSlug');

        if (!$slug) {
            return [];
        }

        // Find the organization by slug
        $organization = $this->organizationRepository->findOneBy(['slug' => $slug]);

        if (!$organization) {
            throw new NotFoundHttpException(sprintf('Organization with slug "%s" not found.', $slug));
        }

        // Check if organization is active
        if (!$organization->isActive()) {
            throw new NotFoundHttpException('This organization is not active.');
        }

        yield $organization;
    }
}
