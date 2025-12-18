<?php
namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $users = $em->getRepository(User::class)->findBy([], ['createdAt' => 'DESC']);
        return $this->render('admin/dashboard.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/block/{id<\\d+>}', name: 'app_admin_block_user', methods: ['POST', 'GET'])]
    public function block(int $id, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $em->getRepository(User::class)->find($id);
        if ($user) {
            $user->setIsActive(false);
            $em->flush();
            $this->addFlash('success', 'User blocked.');
        }
        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/activate/{id<\\d+>}', name: 'app_admin_activate_user', methods: ['POST', 'GET'])]
    public function activate(int $id, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $em->getRepository(User::class)->find($id);
        if ($user) {
            $user->setIsActive(true);
            $em->flush();
            $this->addFlash('success', 'User activated.');
        }
        return $this->redirectToRoute('app_admin_dashboard');
    }
}
