<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Form\OrganizationType;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/organizations')]
#[IsGranted('ROLE_ADMIN')]
class AdminOrganizationController extends AbstractController
{
    #[Route('', name: 'app_admin_organization_index', methods: ['GET'])]
    public function index(OrganizationRepository $organizationRepository): Response
    {
        $organizations = $organizationRepository->findBy([], ['name' => 'ASC']);

        return $this->render('admin/organization/index.html.twig', [
            'organizations' => $organizations,
        ]);
    }

    #[Route('/new', name: 'app_admin_organization_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $organization = new Organization();
        $organization->setIsActive(true);

        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($organization);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Organization "%s" has been created successfully.', $organization->getName()));

            return $this->redirectToRoute('app_admin_organization_index');
        }

        return $this->render('admin/organization/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_organization_show', methods: ['GET'])]
    public function show(Organization $organization): Response
    {
        return $this->render('admin/organization/show.html.twig', [
            'organization' => $organization,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_organization_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Organization $organization, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', sprintf('Organization "%s" has been updated successfully.', $organization->getName()));

            return $this->redirectToRoute('app_admin_organization_index');
        }

        return $this->render('admin/organization/edit.html.twig', [
            'form' => $form,
            'organization' => $organization,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_organization_delete', methods: ['POST'])]
    public function delete(Request $request, Organization $organization, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete-organization-' . $organization->getId(), $request->request->get('_token'))) {
            $name = $organization->getName();
            $entityManager->remove($organization);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Organization "%s" has been deleted successfully.', $name));
        }

        return $this->redirectToRoute('app_admin_organization_index');
    }
}
