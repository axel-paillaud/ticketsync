<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class NotificationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private Security $security,
        private NotificationRepository $notificationRepository,
    ) {}

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [
                'unreadNotificationCount' => 0,
                'recentNotifications' => [],
            ];
        }

        return [
            'unreadNotificationCount' => $this->notificationRepository->countUnreadForUser($user),
            'recentNotifications' => $this->notificationRepository->findRecentForUser($user),
        ];
    }
}