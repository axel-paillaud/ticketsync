<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    #[Route('/{id}/read', name: 'app_notification_read', methods: ['POST'])]
    public function markAsRead(
        Notification $notification,
        NotificationRepository $notificationRepository,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        // CSRF protection
        if (!$this->isCsrfTokenValid('notification_read_' . $notification->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Security: ensure the notification belongs to the current user
        if ($notification->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $notificationRepository->markAsRead($notification);

        // Redirect to the ticket if available, otherwise back to referrer
        if ($notification->getTicket()) {
            $ticket = $notification->getTicket();

            return $this->redirectToRoute('app_ticket_show', [
                'organizationSlug' => $ticket->getOrganization()->getSlug(),
                'ticketId' => $ticket->getId(),
            ]);
        }

        return $this->redirectToRoute('app_home');
    }

    #[Route('/read-all', name: 'app_notification_read_all', methods: ['POST'])]
    public function markAllAsRead(
        NotificationRepository $notificationRepository,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        // CSRF protection
        if (!$this->isCsrfTokenValid('notification_read_all', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $notificationRepository->markAllAsReadForUser($user);

        // Redirect back to where the user came from
        return $this->redirectToRoute('app_home');
    }
}