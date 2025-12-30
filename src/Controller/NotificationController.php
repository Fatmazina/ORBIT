<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    #[Route('/read-all', name: 'app_notifications_read_all', methods: ['GET'])]
    public function readAll(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $notifications = $user->getNotifications();

        foreach ($notifications as $notification) {
            if (!$notification->isRead()) {
                $notification->setIsRead(true);
            }
        }

        $em->flush();

        return $this->redirect($this->generateUrl('app_home'));
    }

    #[Route('/{id}/read', name: 'app_notification_read', methods: ['GET'])]
    public function read(Notification $notification, EntityManagerInterface $em): Response
    {
        if ($notification->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $notification->setIsRead(true);
        $em->flush();

        return $this->redirect($this->generateUrl('app_home'));
    }
}
