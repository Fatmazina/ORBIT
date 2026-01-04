<?php
namespace App\Controller;

use App\Entity\Post;
use App\Entity\User;
use App\Entity\Vote;
use App\Entity\Category;
use App\Entity\Comment;
use App\Entity\Report;
use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        $showAll = (bool) $request->query->get('all', false);
        if ($showAll) {
            $posts = $em->getRepository(Post::class)->findBy(['isArchived' => false]);
            if (is_array($posts)) {
                shuffle($posts);
            }
        } elseif ($selected) {
            $qb = $em->createQueryBuilder()
                ->select('p')
                ->from(Post::class, 'p')
                ->leftJoin('p.categories', 'c')
                ->where('c.id IN (:ids)')
                ->andWhere('p.isArchived = :arch')
                ->setParameter('ids', array_map('intval', $selected))
                ->setParameter('arch', false)
                ->orderBy('p.createdAt', 'DESC')
                ->groupBy('p.id');
            $posts = $qb->getQuery()->getResult();
        } else {
            $posts = $em->getRepository(Post::class)->findBy(['isArchived' => false], ['createdAt' => 'DESC']);
        }
        $voteRepo = $em->getRepository(Vote::class);
        $counts = [];
        $userVotes = [];
        $commentTotals = [];
        $currentUser = $this->getUser();

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
            if ($currentUser instanceof User) {
                $userVotesRaw = $em->getRepository(Vote::class)->createQueryBuilder('v')
                    ->select('IDENTITY(v.post) as postId, v.type')
                    ->where('v.post IN (:ids)')
                    ->andWhere('v.author = :user')
                    ->setParameter('ids', $postIds)
                    ->setParameter('user', $currentUser)
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

        $categories = $em->getRepository(Category::class)->findBy([], ['name' => 'ASC']);
        $recentlyActiveUsers = $em->getRepository(User::class)->findRecentlyActive(8);

        return $this->render('posts/index.html.twig', [
            'posts' => $posts,
            'counts' => $counts,
            'userVotes' => $userVotes,
            'categories' => $categories,
            'selectedCategories' => array_map('intval', $selected),
            'showAll' => $showAll,
            'commentTotals' => $commentTotals ?? [],
            'recentlyActiveUsers' => $recentlyActiveUsers,
        ]);
    }
    
    #[Route('/{id<\\d+>}/report', name: 'app_posts_report', methods: ['POST'])]
    public function report(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('report_post', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $id]);
        }
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }
        if ($post->getAuthor()?->getId() === $user->getId()) {
            $this->addFlash('error', 'You cannot report your own post.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $reason = (string) $request->request->get('reason', '');
        $allowed = ['Spam', 'Harassment or hate', 'Violence', 'Misinformation', 'Other'];
        if ($reason === '' || !in_array($reason, $allowed, true)) {
            $this->addFlash('error', 'Please select a valid reason.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $existing = $em->getRepository(Report::class)->findOneBy(['reportedPost' => $post, 'reporter' => $user, 'status' => 'pending']);
        if ($existing) {
            $this->addFlash('info', 'You have already reported this post.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $report = new Report();
        $report->setReporter($user);
        $report->setReportedPost($post);
        $report->setReason($reason);
        $em->persist($report);

        // Notify Admins about the report
        $admins = $em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()->getResult();
        
        foreach ($admins as $admin) {
            $notification = new Notification();
            $notification->setUser($admin);
            $notification->setType('report');
            $notification->setMessage(sprintf('New report on post "%s" by %s', $post->getTitle(), $user->getUsername()));
            $notification->setRelatedId($post->getId());
            $em->persist($notification);
        }

        $em->flush();
        $this->addFlash('success', 'Report submitted. Our admins will review it.');
        return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
    }
    
    #[Route('/create', name: 'app_posts_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('post_create', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->render('posts/create.html.twig', [
                    'categories' => $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']),
                ]);
            }
            $title = trim((string) $request->request->get('title', ''));
            $content = trim((string) $request->request->get('content', ''));
            $categoryIds = (array) $request->request->all('category_ids');

            if ($title === '' || $content === '' || count($categoryIds) === 0) {
                $this->addFlash('error', 'Please fill in all fields and select categories.');
                // Re-render instead of redirecting to keep input values
                return $this->render('posts/create.html.twig', [
                    'categories' => $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']),
                ]);
            }

            $categories = $em->getRepository(Category::class)->findBy(['id' => array_map('intval', $categoryIds)]);
            if (count($categories) === 0) {
                $this->addFlash('error', 'Invalid categories.');
                return $this->render('posts/create.html.twig', [
                    'categories' => $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']),
                ]);
            }

            $post = new Post();
            $post->setTitle($title);
            $post->setContent($content);
            $post->setAuthor($user);
            foreach ($categories as $cat) {
                $post->addCategory($cat);
            }

            $file = $request->files->get('image');
            if ($file) {
                if (!$file->isValid()) {
                    $this->addFlash('error', 'Image upload failed: ' . $file->getErrorMessage());
                    return $this->render('posts/create.html.twig', [
                        'categories' => $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']),
                    ]);
                }

                $maxSize = 2 * 1024 * 1024; // 2MB
                if ($file->getSize() > $maxSize) {
                    $this->addFlash('error', 'The image is too large. Maximum size allowed is 2MB.');
                    return $this->render('posts/create.html.twig', [
                        'categories' => $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']),
                    ]);
                }

                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
                    $this->addFlash('error', 'Invalid file type. Only JPG, PNG, GIF and WEBP are allowed.');
                    return $this->render('posts/create.html.twig', [
                        'categories' => $em->getRepository(Category::class)->findBy([], ['id' => 'ASC']),
                    ]);
                }

                $uploadsDir = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'posts';
                if (!is_dir($uploadsDir)) {
                    @mkdir($uploadsDir, 0775, true);
                }
                $safeName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                
                // Fallback for when php_fileinfo extension is not enabled
                try {
                    $ext = $file->guessExtension();
                } catch (\Exception $e) {
                    $ext = $file->getClientOriginalExtension();
                }
                
                $ext = $ext ?: 'bin';
                $newFilename = $safeName . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
                $file->move($uploadsDir, $newFilename);
                $post->setImage('/uploads/posts/' . $newFilename);
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
        $comments = $em->getRepository(Comment::class)->findBy(['post' => $post, 'isArchived' => false], ['createdAt' => 'DESC']);
        $voteRepo = $em->getRepository(Vote::class);
        $commentCounts = [];
        $commentUserVotes = [];
        $commentsCount = count($comments);
        foreach ($comments as $c) {
            $commentCounts[$c->getId()] = [
                'like' => $voteRepo->count(['comment' => $c, 'type' => 'like']),
                'dislike' => $voteRepo->count(['comment' => $c, 'type' => 'dislike']),
            ];
            if ($currentUser instanceof User) {
                $cv = $voteRepo->findOneBy(['comment' => $c, 'author' => $currentUser]);
                $commentUserVotes[$c->getId()] = $cv ? $cv->getType() : null;
            }
        }

        return $this->render('posts/show.html.twig', [
            'post' => $post,
            'likes' => $likes,
            'dislikes' => $dislikes,
            'userVote' => $existing ? $existing->getType() : null,
            'comments' => $comments,
            'commentCounts' => $commentCounts,
            'commentUserVotes' => $commentUserVotes,
            'commentsCount' => $commentsCount,
        ]);
    }

    #[Route('/{id<\\d+>}/vote/{type}', name: 'app_posts_vote', methods: ['POST'])]
    public function vote(int $id, string $type, EntityManagerInterface $em, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('vote_post', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $id]);
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

            // Notification for post vote
            if ($post->getAuthor() && $post->getAuthor()->getId() !== $user->getId()) {
                $notification = new Notification();
                $notification->setUser($post->getAuthor());
                $notification->setType('vote');
                $notification->setMessage(sprintf('%s %sd your post "%s"', $user->getUsername(), $type, $post->getTitle()));
                $notification->setRelatedId($post->getId());
                $em->persist($notification);
            }
        } else {
            if ($existing->getType() === $type) {
                $em->remove($existing);
            } else {
                $existing->setType($type);
            }
        }
        $em->flush();

        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept', ''), 'application/json')) {
            return new JsonResponse([
                'success' => true,
                'likes' => $voteRepo->count(['post' => $post, 'type' => 'like']),
                'dislikes' => $voteRepo->count(['post' => $post, 'type' => 'dislike']),
                'userVote' => ($v = $voteRepo->findOneBy(['post' => $post, 'author' => $user])) ? $v->getType() : null,
            ]);
        }

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
    
    #[Route('/{id<\\d+>}/comment', name: 'app_posts_comment', methods: ['POST'])]
    public function addComment(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('add_comment', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $id]);
        }
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }
        $content = trim((string) $request->request->get('content', ''));
        if ($content === '') {
            $this->addFlash('error', 'Comment cannot be empty.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $comment = new Comment();
        $comment->setAuthor($user);
        $comment->setPost($post);
        $comment->setContent($content);
        $comment->setUpdatedAt(new \DateTimeImmutable());
        $em->persist($comment);

        // Notification for comment
        if ($post->getAuthor() && $post->getAuthor()->getId() !== $user->getId()) {
            $notification = new Notification();
            $notification->setUser($post->getAuthor());
            $notification->setType('comment');
            $notification->setMessage(sprintf('%s commented on your post "%s"', $user->getUsername(), $post->getTitle()));
            $notification->setRelatedId($post->getId());
            $em->persist($notification);
        }

        $em->flush();
        $this->addFlash('success', 'Comment added.');
        return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
    }
    
    #[Route('/{postId<\\d+>}/comments/{commentId<\\d+>}/vote/{type}', name: 'app_comments_vote', methods: ['POST', 'GET'])]
    public function voteComment(int $postId, int $commentId, string $type, EntityManagerInterface $em, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('vote_comment', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $postId]);
        }
        $post = $em->getRepository(Post::class)->find($postId);
        $comment = $em->getRepository(Comment::class)->find($commentId);
        if (!$post || !$comment || $comment->getPost()?->getId() !== $post->getId()) {
            return $this->redirectToRoute('app_posts');
        }
        $type = strtolower($type);
        if (!in_array($type, ['like', 'dislike'], true)) {
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $voteRepo = $em->getRepository(Vote::class);
        $existing = $voteRepo->findOneBy(['comment' => $comment, 'author' => $user]);
        if (!$existing) {
            $vote = new Vote();
            $vote->setAuthor($user);
            $vote->setComment($comment);
            $vote->setType($type);
            $em->persist($vote);

            // Notification for comment vote
            if ($comment->getAuthor() && $comment->getAuthor()->getId() !== $user->getId()) {
                $notification = new Notification();
                $notification->setUser($comment->getAuthor());
                $notification->setType('vote');
                $notification->setMessage(sprintf('%s %sd your comment', $user->getUsername(), $type));
                $notification->setRelatedId($post->getId());
                $em->persist($notification);
            }
        } else {
            if ($existing->getType() === $type) {
                $em->remove($existing);
            } else {
                $existing->setType($type);
            }
        }
        $em->flush();

        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept', ''), 'application/json')) {
            return new JsonResponse([
                'success' => true,
                'likes' => $voteRepo->count(['comment' => $comment, 'type' => 'like']),
                'dislikes' => $voteRepo->count(['comment' => $comment, 'type' => 'dislike']),
                'userVote' => ($v = $voteRepo->findOneBy(['comment' => $comment, 'author' => $user])) ? $v->getType() : null,
            ]);
        }

        return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
    }
    
    #[Route('/{postId<\\d+>}/comments/{commentId<\\d+>}/delete', name: 'app_comments_delete', methods: ['POST'])]
    public function deleteComment(int $postId, int $commentId, EntityManagerInterface $em, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('delete_comment', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $postId]);
        }
        $post = $em->getRepository(Post::class)->find($postId);
        $comment = $em->getRepository(Comment::class)->find($commentId);
        if (!$post || !$comment || $comment->getPost()?->getId() !== $post->getId()) {
            return $this->redirectToRoute('app_posts');
        }
        $isCommentAuthor = $comment->getAuthor()?->getId() === $user->getId();
        $isPostAuthor = $post->getAuthor()?->getId() === $user->getId();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        
        // Admins can no longer delete comments permanently unless they are the author of the comment
        // Even if they are the post owner, if they are an admin, they should archive instead
        if (!$isCommentAuthor && ($isAdmin || !$isPostAuthor)) {
            $this->addFlash('error', 'You can only archive this comment, not delete it.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        
        $em->remove($comment);
        $em->flush();
        $this->addFlash('success', 'Comment deleted.');
        return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
    }
    
    #[Route('/{postId<\\d+>}/comments/{commentId<\\d+>}/archive', name: 'app_comments_archive', methods: ['POST'])]
    public function archiveComment(int $postId, int $commentId, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('archive_comment', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $postId]);
        }
        $post = $em->getRepository(Post::class)->find($postId);
        $comment = $em->getRepository(Comment::class)->find($commentId);
        if (!$post || !$comment || $comment->getPost()?->getId() !== $post->getId()) {
            return $this->redirectToRoute('app_posts');
        }
        if ($comment->isArchived()) {
            $this->addFlash('info', 'Comment is already archived.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $comment->setIsArchived(true)->setArchivedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        
        // Notification for comment archive
        if ($comment->getAuthor()) {
            $notification = new Notification();
            $notification->setUser($comment->getAuthor());
            $notification->setType('archive');
            $notification->setMessage('Your comment has been archived by an admin. If you continue to violate the rules, you will be banned.');
            $notification->setRelatedId(null); // Archived items go to empty page
            $em->persist($notification);
        }

        $em->flush();
        $this->addFlash('success', 'Comment archived.');
        return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
    }
    
    #[Route('/{postId<\\d+>}/comments/{commentId<\\d+>}/unarchive', name: 'app_comments_unarchive', methods: ['POST'])]
    public function unarchiveComment(int $postId, int $commentId, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('unarchive_comment', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }
        $post = $em->getRepository(Post::class)->find($postId);
        $comment = $em->getRepository(Comment::class)->find($commentId);
        if (!$post || !$comment || $comment->getPost()?->getId() !== $post->getId()) {
            return $this->redirectToRoute('app_posts');
        }
        if (!$comment->isArchived()) {
            $this->addFlash('info', 'Comment is not archived.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $comment->setIsArchived(false)->setArchivedAt(null)->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', 'Comment unarchived.');
        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/{postId<\\d+>}/comments/{commentId<\\d+>}/report', name: 'app_comments_report', methods: ['POST'])]
    public function reportComment(int $postId, int $commentId, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('report_comment', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $postId]);
        }
        $post = $em->getRepository(Post::class)->find($postId);
        $comment = $em->getRepository(Comment::class)->find($commentId);

        if (!$post || !$comment || $comment->getPost()?->getId() !== $post->getId()) {
            return $this->redirectToRoute('app_posts');
        }

        if ($comment->getAuthor()?->getId() === $user->getId()) {
            $this->addFlash('error', 'You cannot report your own comment.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }

        $reason = trim((string) $request->request->get('reason', ''));
        $allowed = ['Spam', 'Harassment or hate', 'Violence', 'Misinformation', 'Other'];
        if ($reason === '' || !in_array($reason, $allowed, true)) {
            $this->addFlash('error', 'Please select a valid reason.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }

        $report = new Report();
        $report->setReporter($user);
        $report->setReportedComment($comment);
        $report->setReason($reason);
        $em->persist($report);

        // Notify admins
        $admins = $em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()->getResult();

        foreach ($admins as $admin) {
            $notification = new Notification();
            $notification->setUser($admin);
            $notification->setType('report');
            $notification->setMessage(sprintf('New report on a comment by %s', $user->getUsername()));
            $notification->setRelatedId($post->getId());
            $em->persist($notification);
        }

        $em->flush();
        $this->addFlash('success', 'Thank you for your report. It will be reviewed by an administrator.');
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
            if (!$this->isCsrfTokenValid('post_edit', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_posts_edit', ['id' => $post->getId()]);
            }
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

            // Image handling for edit
            $file = $request->files->get('image');
            if ($file) {
                if (!$file->isValid()) {
                    $this->addFlash('error', 'Image upload failed: ' . $file->getErrorMessage());
                    return $this->redirectToRoute('app_posts_edit', ['id' => $post->getId()]);
                }

                $maxSize = 2 * 1024 * 1024; // 2MB
                if ($file->getSize() > $maxSize) {
                    $this->addFlash('error', 'The image is too large. Maximum size allowed is 2MB.');
                    return $this->redirectToRoute('app_posts_edit', ['id' => $post->getId()]);
                }

                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
                    $this->addFlash('error', 'Invalid file type. Only JPG, PNG, GIF and WEBP are allowed.');
                    return $this->redirectToRoute('app_posts_edit', ['id' => $post->getId()]);
                }

                $uploadsDir = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'posts';
                if (!is_dir($uploadsDir)) {
                    @mkdir($uploadsDir, 0775, true);
                }
                $safeName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                
                try {
                    $ext = $file->guessExtension();
                } catch (\Exception $e) {
                    $ext = $file->getClientOriginalExtension();
                }
                
                $ext = $ext ?: 'bin';
                $newFilename = $safeName . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
                
                // Optional: remove old image if it exists
                if ($post->getImage()) {
                    $oldImagePath = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $post->getImage()), DIRECTORY_SEPARATOR);
                    if (file_exists($oldImagePath) && is_file($oldImagePath)) {
                        @unlink($oldImagePath);
                    }
                }

                $file->move($uploadsDir, $newFilename);
                $post->setImage('/uploads/posts/' . $newFilename);
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
        if (!$this->isCsrfTokenValid('delete_post', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
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
    
    #[Route('/{id<\\d+>}/archive', name: 'app_posts_archive', methods: ['POST', 'GET'])]
    public function archive(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('archive_post', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_posts_show', ['id' => $id]);
        }
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }
        if ($post->isArchived()) {
            $this->addFlash('info', 'Post is already archived.');
            return $this->redirectToRoute('app_posts_show', ['id' => $post->getId()]);
        }
        $post->setIsArchived(true)->setArchivedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        
        // Notification for post archive
        if ($post->getAuthor()) {
            $notification = new Notification();
            $notification->setUser($post->getAuthor());
            $notification->setType('archive');
            $notification->setMessage(sprintf('Your post "%s" has been archived by an admin. If you continue to violate the rules, you will be banned.', $post->getTitle()));
            $notification->setRelatedId(null); // Archived items go to empty page
            $em->persist($notification);
        }

        $em->flush();
        $this->addFlash('success', 'Post archived.');
        return $this->redirectToRoute('app_admin_dashboard');
    }
    
    #[Route('/{id<\\d+>}/unarchive', name: 'app_posts_unarchive', methods: ['POST', 'GET'])]
    public function unarchive(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('unarchive_post', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_dashboard');
        }
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return $this->redirectToRoute('app_admin_dashboard');
        }
        if (!$post->isArchived()) {
            $this->addFlash('info', 'Post is not archived.');
            return $this->redirectToRoute('app_admin_dashboard');
        }
        $post->setIsArchived(false)->setArchivedAt(null)->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', 'Post unarchived.');
        return $this->redirectToRoute('app_admin_dashboard');
    }
}
