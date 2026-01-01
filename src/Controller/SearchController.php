<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = $request->query->get('q', '');
        $users = [];
        $posts = [];

        if ($query !== '') {
            $users = $em->getRepository(User::class)->createQueryBuilder('u')
                ->where('u.username LIKE :query OR u.email LIKE :query OR u.bio LIKE :query')
                ->setParameter('query', '%' . $query . '%')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();

            $posts = $em->getRepository(Post::class)->createQueryBuilder('p')
                ->where('p.title LIKE :query OR p.content LIKE :query')
                ->andWhere('p.isArchived = :archived')
                ->setParameter('query', '%' . $query . '%')
                ->setParameter('archived', false)
                ->orderBy('p.createdAt', 'DESC')
                ->setMaxResults(30)
                ->getQuery()
                ->getResult();
        }

        return $this->render('search/results.html.twig', [
            'query' => $query,
            'users' => $users,
            'posts' => $posts,
        ]);
    }
}
