<?php
namespace App\Controller;

use App\Entity\User;
use App\Entity\Post;
use App\Entity\Vote;
use App\Entity\Comment;
use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_posts');
        }
        return $this->render('home/landing.html.twig');
    }

    #[Route('/following', name: 'app_following')]
    public function following(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $followingIds = $user->getFollowing()->map(fn(User $u) => $u->getId())->toArray();

        if (empty($followingIds)) {
            $posts = [];
        } else {
            $posts = $em->getRepository(Post::class)->createQueryBuilder('p')
                ->where('p.author IN (:ids)')
                ->andWhere('p.isArchived = :arch')
                ->setParameter('ids', $followingIds)
                ->setParameter('arch', false)
                ->orderBy('p.createdAt', 'DESC')
                ->getQuery()
                ->getResult();
        }

        $voteRepo = $em->getRepository(Vote::class);
        $counts = [];
        $userVotes = [];
        $commentTotals = [];

        // Optimize: Pre-fetch counts and user votes for all posts to avoid N+1 queries
        $postIds = array_map(fn($p) => $p->getId(), $posts);

        if (!empty($postIds)) {
            // Get vote counts
            $voteCountsRaw = $em->getRepository(Vote::class)->createQueryBuilder('v')
                ->select('IDENTITY(v.post) as postId, v.type, COUNT(v.id) as count')
                ->where('v.post IN (:ids)')
                ->setParameter('ids', $postIds)
                ->groupBy('postId, v.type')
                ->getQuery()
                ->getResult();
            
            foreach ($postIds as $id) {
                $counts[$id] = ['like' => 0, 'dislike' => 0];
            }
            foreach ($voteCountsRaw as $row) {
                $counts[$row['postId']][$row['type']] = (int) $row['count'];
            }

            // Get comment totals
            $commentTotalsRaw = $em->getRepository(Comment::class)->createQueryBuilder('c')
                ->select('IDENTITY(c.post) as postId, COUNT(c.id) as count')
                ->where('c.post IN (:ids)')
                ->andWhere('c.isArchived = :arch')
                ->setParameter('ids', $postIds)
                ->setParameter('arch', false)
                ->groupBy('postId')
                ->getQuery()
                ->getResult();
            
            foreach ($postIds as $id) {
                $commentTotals[$id] = 0;
            }
            foreach ($commentTotalsRaw as $row) {
                $commentTotals[$row['postId']] = (int) $row['count'];
            }

            // Get user votes
            if ($user instanceof User) {
                $userVotesRaw = $em->getRepository(Vote::class)->createQueryBuilder('v')
                    ->select('IDENTITY(v.post) as postId, v.type')
                    ->where('v.post IN (:ids)')
                    ->andWhere('v.author = :user')
                    ->setParameter('ids', $postIds)
                    ->setParameter('user', $user)
                    ->getQuery()
                    ->getResult();
                
                foreach ($postIds as $id) {
                    $userVotes[$id] = null;
                }
                foreach ($userVotesRaw as $row) {
                    $userVotes[$row['postId']] = $row['type'];
                }
            }
        }

        return $this->render('posts/index.html.twig', [
            'posts' => $posts,
            'counts' => $counts,
            'userVotes' => $userVotes,
            'commentTotals' => $commentTotals,
            'categories' => $em->getRepository(Category::class)->findBy([], ['name' => 'ASC']),
            'selectedCategories' => [],
            'showAll' => false,
            'isFollowingFeed' => true,
            'recentlyActiveUsers' => $em->getRepository(User::class)->findRecentlyActive(8),
        ]);
    }
}