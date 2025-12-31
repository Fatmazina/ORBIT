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
        return $this->render('home/index.html.twig');
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

        foreach ($posts as $p) {
            $counts[$p->getId()] = [
                'like' => $voteRepo->count(['post' => $p, 'type' => 'like']),
                'dislike' => $voteRepo->count(['post' => $p, 'type' => 'dislike']),
            ];
            $commentTotals[$p->getId()] = $em->getRepository(Comment::class)->count(['post' => $p, 'isArchived' => false]);
            
            $existing = $voteRepo->findOneBy(['post' => $p, 'author' => $user]);
            $userVotes[$p->getId()] = $existing ? $existing->getType() : null;
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
        ]);
    }
}