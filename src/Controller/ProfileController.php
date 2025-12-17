<?php
namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    #[Route('/profile/{id<\\d+>}', name: 'app_profile', methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('profile/show.html.twig', [
            'profileUser' => $user,
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username', $user->getUsername() ?? ''));
            $bio = (string) $request->request->get('bio', '');
            $newPassword = (string) $request->request->get('new_password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            if ($username !== '') {
                $user->setUsername($username);
            }
            $user->setBio($bio !== '' ? $bio : null);

            if ($newPassword !== '') {
                if ($newPassword === $confirmPassword) {
                    $hashed = $passwordHasher->hashPassword($user, $newPassword);
                    $user->setPassword($hashed);
                } else {
                    $this->addFlash('error', 'Passwords do not match.');
                    return $this->redirectToRoute('app_profile_edit');
                }
            }

            $file = $request->files->get('profile_image');
            if ($file) {
                $uploadsDir = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile';
                if (!is_dir($uploadsDir)) {
                    @mkdir($uploadsDir, 0775, true);
                }
                $safeName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $ext = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
                if (!$ext) {
                    try {
                        $ext = $file->guessExtension() ?: 'bin';
                    } catch (\Throwable $e) {
                        $ext = 'bin';
                    }
                }
                $newFilename = $safeName . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
                $file->move($uploadsDir, $newFilename);
                $user->setProfileImage('/uploads/profile/' . $newFilename);
            }

            $user->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', 'Profile updated successfully.');
            return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
        }

        return $this->render('profile/edit.html.twig', [
            'user' => $user,
        ]);
    }
}
