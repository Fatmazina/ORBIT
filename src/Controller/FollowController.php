<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class FollowController extends AbstractController
{
    #[Route('/user/{id}/follow', name: 'app_user_follow', methods: ['POST'])]
    public function follow(User $user, EntityManagerInterface $em): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser === $user) {
            $this->addFlash('danger', 'You cannot follow yourself.');
            return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
        }

        $currentUser->follow($user);
        $em->flush();

        $this->addFlash('success', sprintf('You are now following %s.', $user->getUsername()));

        return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
    }

    #[Route('/user/{id}/unfollow', name: 'app_user_unfollow', methods: ['POST'])]
    public function unfollow(User $user, EntityManagerInterface $em): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $currentUser->unfollow($user);
        $em->flush();

        $this->addFlash('success', sprintf('You have unfollowed %s.', $user->getUsername()));

        return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
    }
}
