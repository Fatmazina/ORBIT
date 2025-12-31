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
    #[Route('', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        // Stats for charts
        $userCount = $em->getRepository(User::class)->count([]);
        $postCount = $em->getRepository(Post::class)->count([]);
        $commentCount = $em->getRepository(Comment::class)->count([]);
        $reportCount = $em->getRepository(Report::class)->count(['status' => 'pending', 'isSeen' => false]);

        // Data for charts (Last 7 days registrations)
        $sevenDaysAgo = new \DateTime('-7 days');
        $recentUsers = $em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.createdAt >= :date')
            ->setParameter('date', $sevenDaysAgo)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $registrations = [];
        $regData = [];
        // Initialize last 7 days with 0
        for ($i = 6; $i >= 0; $i--) {
            $date = (new \DateTime())->modify("-$i days")->format('Y-m-d');
            $regData[$date] = 0;
        }

        foreach ($recentUsers as $user) {
            $date = $user->getCreatedAt()->format('Y-m-d');
            if (isset($regData[$date])) {
                $regData[$date]++;
            }
        }

        foreach ($regData as $date => $count) {
            $registrations[] = ['date' => $date, 'count' => $count];
        }

        // Daily Engagement (Posts & Comments last 7 days)
        $recentPosts = $em->getRepository(Post::class)->createQueryBuilder('p')
            ->where('p.createdAt >= :date')
            ->setParameter('date', $sevenDaysAgo)
            ->getQuery()->getResult();
        
        $recentComments = $em->getRepository(Comment::class)->createQueryBuilder('c')
            ->where('c.createdAt >= :date')
            ->setParameter('date', $sevenDaysAgo)
            ->getQuery()->getResult();

        $engagementData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = (new \DateTime())->modify("-$i days")->format('Y-m-d');
            $engagementData[$date] = ['posts' => 0, 'comments' => 0];
        }

        foreach ($recentPosts as $p) {
            $date = $p->getCreatedAt()->format('Y-m-d');
            if (isset($engagementData[$date])) $engagementData[$date]['posts']++;
        }
        foreach ($recentComments as $c) {
            $date = $c->getCreatedAt()->format('Y-m-d');
            if (isset($engagementData[$date])) $engagementData[$date]['comments']++;
        }

        $engagement = [];
        foreach ($engagementData as $date => $counts) {
            $engagement[] = ['date' => $date, 'posts' => $counts['posts'], 'comments' => $counts['comments']];
        }

        // User Verification Status
        $verifiedCount = $em->getRepository(User::class)->count(['isEmailVerified' => true]);
        $unverifiedCount = $userCount - $verifiedCount;

        // Data for charts (Posts by category)
        $postsByCategory = $em->createQuery('
            SELECT c.name, COUNT(p.id) as count
            FROM App\Entity\Category c
            LEFT JOIN c.posts p
            GROUP BY c.name
        ')->getResult();

        $usersQuery = $em->getRepository(User::class)->createQueryBuilder('u');
        
        $search = $request->query->get('q');
        if ($search) {
            $usersQuery->where('u.username LIKE :q OR u.email LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        $users = $usersQuery->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
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
            'stats' => [
                'users' => $userCount,
                'posts' => $postCount,
                'comments' => $commentCount,
                'pending_reports' => $reportCount,
            ],
            'chartData' => [
                'registrations' => $registrations,
                'categories' => $postsByCategory,
                'engagement' => $engagement,
                'verification' => [
                    ['status' => 'Verified', 'count' => $verifiedCount],
                    ['status' => 'Unverified', 'count' => $unverifiedCount],
                ]
            ]
        ]);
    }

    #[Route('/block/{id<\\d+>}', name: 'app_admin_block_user', methods: ['POST'])]
    public function block(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('user_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $user = $em->getRepository(User::class)->find($id);
        if ($user) {
            $user->setIsActive(false);
            $em->flush();
            $this->addFlash('success', 'User blocked.');
        }
        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/activate/{id<\\d+>}', name: 'app_admin_activate_user', methods: ['POST'])]
    public function activate(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('user_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

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
        if (!$this->isCsrfTokenValid('add_category', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

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
        return $this->redirect($this->generateUrl('app_admin_dashboard') . '#categories');
    }

    #[Route('/categories/{id<\\d+>}/edit', name: 'app_admin_edit_category', methods: ['POST'])]
    public function editCategory(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('edit_category', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $cat = $em->getRepository(Category::class)->find($id);
        if (!$cat) {
            $this->addFlash('error', 'Category not found.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $name = trim((string) $request->request->get('name', ''));
        $description = (string) $request->request->get('description', '');

        if ($name === '') {
            $this->addFlash('error', 'Category name is required.');
            return $this->redirect($this->generateUrl('app_admin_dashboard') . '#categories');
        }

        $existing = $em->getRepository(Category::class)->findOneBy(['name' => $name]);
        if ($existing && $existing->getId() !== $cat->getId()) {
            $this->addFlash('error', 'Category name already exists.');
            return $this->redirect($this->generateUrl('app_admin_dashboard') . '#categories');
        }

        $cat->setName($name);
        $cat->setDescription($description !== '' ? $description : null);
        $em->flush();

        $this->addFlash('success', 'Category updated.');
        return $this->redirect($this->generateUrl('app_admin_dashboard') . '#categories');
    }

    #[Route('/categories/{id<\\d+>}/delete', name: 'app_admin_delete_category', methods: ['POST'])]
    public function deleteCategory(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('delete_category', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $cat = $em->getRepository(Category::class)->find($id);
        if (!$cat) {
            $this->addFlash('error', 'Category not found.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        // Check if category has posts
        if (count($cat->getPosts()) > 0) {
            $this->addFlash('error', 'Cannot delete category that has posts. Remove or reassign posts first.');
            return $this->redirect($this->generateUrl('app_admin_dashboard') . '#categories');
        }

        $em->remove($cat);
        $em->flush();

        $this->addFlash('success', 'Category deleted.');
        return $this->redirect($this->generateUrl('app_admin_dashboard') . '#categories');
    }

    #[Route('/reports/{id}/resolve', name: 'app_admin_resolve_report', methods: ['POST'])]
    public function resolveReport(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('report_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $report = $em->getRepository(Report::class)->find($id);
        if ($report) {
            $report->setStatus('resolved');
            $report->setResolvedAt(new \DateTimeImmutable());
            $report->setResolvedBy($this->getUser());
            $em->flush();
            $this->addFlash('success', 'Report marked as resolved.');
        }
        return $this->redirect($this->generateUrl('app_admin_dashboard') . '#reports');
    }

    #[Route('/reports/{id}/dismiss', name: 'app_admin_dismiss_report', methods: ['POST'])]
    public function dismissReport(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('report_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $report = $em->getRepository(Report::class)->find($id);
        if ($report) {
            $report->setStatus('dismissed');
            $report->setResolvedAt(new \DateTimeImmutable());
            $report->setResolvedBy($this->getUser());
            $em->flush();
            $this->addFlash('success', 'Report dismissed.');
        }
        return $this->redirect($this->generateUrl('app_admin_dashboard') . '#reports');
    }

    #[Route('/reports/mark-all-seen', name: 'app_admin_reports_mark_seen', methods: ['POST'])]
    public function markAllSeen(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $reports = $em->getRepository(Report::class)->findBy(['isSeen' => false, 'status' => 'pending']);
        foreach ($reports as $report) {
            $report->setIsSeen(true);
        }
        $em->flush();
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
