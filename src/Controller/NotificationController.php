<?php

namespace App\Controller;

use App\Entity\User;
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
    #[Route('/', name: 'app_notifications', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('notifications/index.html.twig', [
            'notifications' => $user->getNotifications(),
        ]);
    }

    #[Route('/read-all', name: 'app_notifications_read_all', methods: ['GET'])]
    public function readAll(EntityManagerInterface $em, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        
        $notifications = $user->getNotifications();

        foreach ($notifications as $notification) {
            if (!$notification->isRead()) {
                $notification->setIsRead(true);
            }
        }

        $em->flush();

        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

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

        $type = $notification->getType();
        $relatedId = $notification->getRelatedId();

        if ($type === 'archive') {
            return $this->render('notifications/archived.html.twig', [
                'message' => $notification->getMessage()
            ]);
        }

        if ($relatedId) {
            if ($type === 'report' && $this->isGranted('ROLE_ADMIN')) {
                // Redirect to admin dashboard with reports tab active (via hash)
                return $this->redirect($this->generateUrl('app_admin_dashboard') . '#reports');
            }
            
            // For votes and comments, redirect to the post
            return $this->redirectToRoute('app_posts_show', ['id' => $relatedId]);
        }

        return $this->redirect($this->generateUrl('app_home'));
    }
}
