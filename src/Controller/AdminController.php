<?php

namespace App\Controller;

use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('', name: 'app_admin_index', methods: ['GET'])]
    public function index(TicketRepository $ticketRepository, Request $request): Response
    {
        // Check if we should include resolved tickets (default: false)
        $includeResolved = $request->query->getBoolean('resolved', false);

        $tickets = $ticketRepository->findAllOrderedByPriority($includeResolved);

        return $this->render('admin/index.html.twig', [
            'tickets' => $tickets,
            'includeResolved' => $includeResolved,
        ]);
    }
}
