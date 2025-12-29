<?php
namespace App\Controller;

use App\Entity\Post;
use App\Entity\User;
use App\Entity\Vote;
use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/posts')]
class PostController extends AbstractController
{
    #[Route('/', name: 'app_posts', methods: ['GET'])]
    public function index(EntityManagerInterface $em, Request $request): Response
    {
        $selected = (array) $request->query->all('categories');
        if ($selected) {
            $qb = $em->createQueryBuilder()
                ->select('p')
                ->from(Post::class, 'p')
                ->leftJoin('p.categories', 'c')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', array_map('intval', $selected))
                ->orderBy('p.createdAt', 'DESC')
                ->groupBy('p.id');
            $posts = $qb->getQuery()->getResult();
        } else {
            $posts = $em->getRepository(Post::class)->findBy([], ['createdAt' => 'DESC']);
        }
        $voteRepo = $em->getRepository(Vote::class);
        $counts = [];
        $userVotes = [];
        $currentUser = $this->getUser();
        foreach ($posts as $p) {
            $counts[$p->getId()] = [
                'like' => $voteRepo->count(['post' => $p, 'type' => 'like']),
                'dislike' => $voteRepo->count(['post' => $p, 'type' => 'dislike']),
            ];
            if ($currentUser instanceof User) {
                $existing = $voteRepo->findOneBy(['post' => $p, 'author' => $currentUser]);
                $userVotes[$p->getId()] = $existing ? $existing->getType() : null;
            }
        }
        $categories = $em->getRepository(Category::class)->findBy([], ['name' => 'ASC']);
        return $this->render('posts/index.html.twig', [
            'posts' => $posts,
            'counts' => $counts,
            'userVotes' => $userVotes,
            'categories' => $categories,
            'selectedCategories' => array_map('intval', $selected),
        ]);
    }

    #[Route('/create', name: 'app_posts_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $title = trim((string) $request->request->get('title', ''));
            $content = trim((string) $request->request->get('content', ''));
            $categoryIds = (array) $request->request->all('category_ids');

            if ($title === '' || $content === '' || count($categoryIds) === 0) {
                $this->addFlash('error', 'Please fill in all fields and select categories.');
                return $this->redirectToRoute('app_posts_create');
            }

            $categories = $em->getRepository(Category::class)->findBy(['id' => array_map('intval', $categoryIds)]);
            if (count($categories) === 0) {
                $this->addFlash('error', 'Invalid categories.');
                return $this->redirectToRoute('app_posts_create');
            }

            $post = new Post();
            $post->setTitle($title);
            $post->setContent($content);
            $post->setAuthor($user);
            foreach ($categories as $cat) {
                $post->addCategory($cat);
            }
            $post->setUpdatedAt(new \DateTimeImmutable());
            $em->persist($post);
            $em->flush();
            $this->addFlash('success', 'Post created.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }

        $categories = $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']);
        return $this->render('posts/create.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/{id<\\d+>}', name: 'app_posts_show', methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }
        $post->incrementViews();
        $post->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $likes = $em->getRepository(Vote::class)->count(['post' => $post, 'type' => 'like']);
        $dislikes = $em->getRepository(Vote::class)->count(['post' => $post, 'type' => 'dislike']);
        $existing = null;
        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $existing = $em->getRepository(Vote::class)->findOneBy(['post' => $post, 'author' => $currentUser]);
        }

        return $this->render('posts/show.html.twig', [
            'post' => $post,
            'likes' => $likes,
            'dislikes' => $dislikes,
            'userVote' => $existing ? $existing->getType() : null,
        ]);
    }

    #[Route('/{id<\\d+>}/vote/{type}', name: 'app_posts_vote', methods: ['POST', 'GET'])]
    public function vote(int $id, string $type, EntityManagerInterface $em, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }

        $type = strtolower($type);
        if (!in_array($type, ['like', 'dislike'], true)) {
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }

        $voteRepo = $em->getRepository(Vote::class);
        $existing = $voteRepo->findOneBy(['post' => $post, 'author' => $user]);

        if (!$existing) {
            $vote = new Vote();
            $vote->setAuthor($user);
            $vote->setPost($post);
            $vote->setType($type);
            $em->persist($vote);
        } else {
            if ($existing->getType() === $type) {
                $em->remove($existing);
            } else {
                $existing->setType($type);
            }
        }

        $em->flush();
        $from = (string) $request->query->get('from', '');
        if ($from === 'list' || str_contains((string) $request->headers->get('referer', ''), '/posts')) {
            return $this->redirectToRoute('app_posts');
        }
        if ($from === 'profile' || str_contains((string) $request->headers->get('referer', ''), '/profile')) {
            $profileId = (int) $request->query->get('profileId', $post->getAuthor()->getId());
            return $this->redirectToRoute('app_profile', ['id' => $profileId]);
        }
        return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
    }
    
    #[Route('/{id<\\d+>}/edit', name: 'app_posts_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }
        if ($post->getAuthor()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You cannot edit this post.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        if ($request->isMethod('POST')) {
            $title = trim((string) $request->request->get('title', ''));
            $content = trim((string) $request->request->get('content', ''));
            $categoryIds = (array) $request->request->all('category_ids');
            if ($title === '' || $content === '' || count($categoryIds) === 0) {
                $this->addFlash('error', 'Please fill in all fields and select categories.');
                return $this->redirectToRoute('app_posts_edit', ['id' => $post->getId()]);
            }
            $categories = $em->getRepository(Category::class)->findBy(['id' => array_map('intval', $categoryIds)]);
            if (count($categories) === 0) {
                $this->addFlash('error', 'Invalid categories.');
                return $this->redirectToRoute('app_posts_edit', ['id' => $post->getId()]);
            }
            $post->setTitle($title);
            $post->setContent($content);
            foreach ($post->getCategories() as $existingCat) {
                $post->removeCategory($existingCat);
            }
            foreach ($categories as $cat) {
                $post->addCategory($cat);
            }
            $post->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', 'Post updated.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $categories = $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']);
        return $this->render('posts/edit.html.twig', [
            'post' => $post,
            'categories' => $categories,
        ]);
    }
    
    #[Route('/{id<\\d+>}/delete', name: 'app_posts_delete', methods: ['POST', 'GET'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }
        if ($post->getAuthor()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You cannot delete this post.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $authorId = $post->getAuthor()?->getId();
        $em->remove($post);
        $em->flush();
        $this->addFlash('success', 'Post deleted.');
        $from = (string) $request->query->get('from', '');
        if ($from === 'profile' && $authorId) {
            return $this->redirectToRoute('app_profile', ['id' => $authorId]);
        }
        if ($from === 'list' || str_contains((string) $request->headers->get('referer', ''), '/posts')) {
            return $this->redirectToRoute('app_posts');
        }
        return $this->redirectToRoute('app_posts');
    }
}
