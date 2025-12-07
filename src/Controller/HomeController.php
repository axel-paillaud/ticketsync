<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        // If user is authenticated, redirect to their organization
        if ($user && $user->getOrganization()) {
            return $this->redirectToRoute('app_ticket_index', [
                'organizationSlug' => $user->getOrganization()->getSlug()
            ]);
        }

        // Otherwise, redirect to login
        return $this->redirectToRoute('app_login');
    }
}
