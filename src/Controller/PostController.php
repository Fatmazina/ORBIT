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
    public function index(EntityManagerInterface $em): Response
    {
        $posts = $em->getRepository(Post::class)->findBy([], ['createdAt' => 'DESC']);
        $voteRepo = $em->getRepository(Vote::class);
        $counts = [];
        foreach ($posts as $p) {
            $counts[$p->getId()] = [
                'like' => $voteRepo->count(['post' => $p, 'type' => 'like']),
                'dislike' => $voteRepo->count(['post' => $p, 'type' => 'dislike']),
            ];
        }
        return $this->render('posts/index.html.twig', [
            'posts' => $posts,
            'counts' => $counts,
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
            $categoryId = (int) $request->request->get('category_id', 0);

            if ($title === '' || $content === '' || $categoryId <= 0) {
                $this->addFlash('error', 'Please fill in all fields.');
                return $this->redirectToRoute('app_posts_create');
            }

            $category = $em->getRepository(Category::class)->find($categoryId);
            if (!$category) {
                $this->addFlash('error', 'Invalid category.');
                return $this->redirectToRoute('app_posts_create');
            }

            $post = new Post();
            $post->setTitle($title);
            $post->setContent($content);
            $post->setAuthor($user);
            $post->setCategory($category);
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

        return $this->render('posts/show.html.twig', [
            'post' => $post,
            'likes' => $likes,
            'dislikes' => $dislikes,
        ]);
    }

    #[Route('/{id<\\d+>}/vote/{type}', name: 'app_posts_vote', methods: ['POST', 'GET'])]
    public function vote(int $id, string $type, EntityManagerInterface $em): Response
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
        return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
    }
}
