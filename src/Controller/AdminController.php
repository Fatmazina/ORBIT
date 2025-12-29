<?php
namespace App\Controller;

use App\Entity\User;
use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Comment;
use App\Entity\Report;
use Symfony\Component\HttpFoundation\Request;
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
        $categories = $em->getRepository(Category::class)->findBy([], ['name' => 'ASC']);
        $archivedPosts = $em->getRepository(Post::class)->findBy(['isArchived' => true], ['archivedAt' => 'DESC']);
        $archivedComments = $em->getRepository(Comment::class)->findBy(['isArchived' => true], ['archivedAt' => 'DESC']);
        $reports = $em->getRepository(Report::class)->findBy(['status' => 'pending'], ['createdAt' => 'DESC']);
        return $this->render('admin/dashboard.html.twig', [
            'users' => $users,
            'categories' => $categories,
            'archivedPosts' => $archivedPosts,
            'archivedComments' => $archivedComments,
            'reports' => $reports,
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

    #[Route('/categories/add', name: 'app_admin_add_category', methods: ['POST'])]
    public function addCategory(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $name = trim((string) $request->request->get('name', ''));
        $description = (string) $request->request->get('description', '');
        if ($name === '') {
            $this->addFlash('error', 'Category name is required.');
            return $this->redirectToRoute('app_admin_dashboard');
        }
        $existing = $em->getRepository(Category::class)->findOneBy(['name' => $name]);
        if ($existing) {
            $this->addFlash('error', 'Category already exists.');
            return $this->redirectToRoute('app_admin_dashboard');
        }
        $cat = new Category();
        $cat->setName($name);
        $cat->setDescription($description !== '' ? $description : null);
        $cat->setCreatedAt(new \DateTimeImmutable());
        $em->persist($cat);
        $em->flush();
        $this->addFlash('success', 'Category added.');
        return $this->redirectToRoute('app_admin_dashboard');
    }
}
