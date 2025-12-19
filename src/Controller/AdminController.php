<?php

namespace App\Controller;

use App\Repository\TicketRepository;
use App\Repository\ActivityRepository;
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

    #[Route('/activities', name: 'app_admin_activities', methods: ['GET'])]
    public function activities(ActivityRepository $activityRepository): Response
    {
        // Get all activities across all organizations
        $activities = $activityRepository->findAllOrderedByDate();

        // Group by month and calculate totals
        $activitiesByMonth = [];
        $totalHours = 0.0;

        foreach ($activities as $activity) {
            // Group key: YYYY-MM
            $monthKey = $activity->getWorkDate()->format('Y-m');

            if (!isset($activitiesByMonth[$monthKey])) {
                $activitiesByMonth[$monthKey] = [
                    'label' => $activity->getWorkDate()->format('F Y'), // "December 2025"
                    'entries' => [],
                    'totalHours' => 0.0,
                ];
            }

            $activitiesByMonth[$monthKey]['entries'][] = $activity;
            $activitiesByMonth[$monthKey]['totalHours'] += (float) $activity->getHours();

            // Global totals
            $totalHours += (float) $activity->getHours();
        }

        // Sort by month DESC (most recent first)
        krsort($activitiesByMonth);

        return $this->render('admin/activities.html.twig', [
            'activitiesByMonth' => $activitiesByMonth,
            'totalEntries' => count($activities),
            'totalHours' => $totalHours,
        ]);
    }
}
