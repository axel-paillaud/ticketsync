<?php

namespace App\Controller;

use App\Repository\TicketRepository;
use App\Repository\TimeEntryRepository;
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

    #[Route('/time-entries', name: 'app_admin_time_entries', methods: ['GET'])]
    public function timeEntries(TimeEntryRepository $timeEntryRepository): Response
    {
        // Get all time entries across all organizations
        $timeEntries = $timeEntryRepository->findAllOrderedByDate();

        // Group by month and calculate totals
        $entriesByMonth = [];
        $totalHours = 0.0;
        $totalBilled = 0.0;

        foreach ($timeEntries as $entry) {
            // Group key: YYYY-MM
            $monthKey = $entry->getWorkDate()->format('Y-m');

            if (!isset($entriesByMonth[$monthKey])) {
                $entriesByMonth[$monthKey] = [
                    'label' => $entry->getWorkDate()->format('F Y'), // "December 2025"
                    'entries' => [],
                    'totalHours' => 0.0,
                    'totalBilled' => 0.0,
                ];
            }

            $entriesByMonth[$monthKey]['entries'][] = $entry;
            $entriesByMonth[$monthKey]['totalHours'] += (float) $entry->getHours();
            $entriesByMonth[$monthKey]['totalBilled'] += (float) $entry->getBilledAmount();

            // Global totals
            $totalHours += (float) $entry->getHours();
            $totalBilled += (float) $entry->getBilledAmount();
        }

        // Sort by month DESC (most recent first)
        krsort($entriesByMonth);

        return $this->render('admin/time_entries.html.twig', [
            'entriesByMonth' => $entriesByMonth,
            'totalEntries' => count($timeEntries),
            'totalHours' => $totalHours,
            'totalBilled' => $totalBilled,
        ]);
    }
}
