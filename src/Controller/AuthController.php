<?php
namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/auth')]
class AuthController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        ?CsrfTokenManagerInterface $csrfTokenManager = null
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        // If no CSRF token manager is available, disable CSRF protection on the form
        $formOptions = [];
        if (null === $csrfTokenManager) {
            $logger->warning('CsrfTokenManagerInterface not available; disabling form CSRF protection for register form.');
            $formOptions['csrf_protection'] = false;
        }

        $form = $this->createForm(RegistrationFormType::class, $user, $formOptions);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $passwordHasher->hashPassword($user, $form->get('plainPassword')->getData())
            );

            $token = bin2hex(random_bytes(32));
            $user->setEmailVerificationToken($token);
            $user->setRoles(['ROLE_USER']);

            $em->persist($user);
            $em->flush();

            $this->sendVerificationEmail($user, $mailer, $logger);

            $this->addFlash('success', 'Registration successful! Check your email to verify your account.');
            return $this->redirectToRoute('app_login');
        }

        // If the form was submitted but not valid, log details to help debugging (e.g. CSRF failures)
        if ($form->isSubmitted() && !$form->isValid()) {
            $hasCsrfError = false;
            foreach ($form->getErrors(true) as $error) {
                $field = $error->getOrigin()?->getName() ?? 'form';
                $message = sprintf("Form error (field: %s): %s", $field, $error->getMessage());
                $logger->warning($message);

                // If this appears to be a CSRF failure, add contextual logs and a clearer flash for users
                $lower = mb_strtolower($error->getMessage());
                if (str_contains($lower, 'csrf') || str_contains($lower, 'token')) {
                    $hasCsrfError = true;
                    $logger->warning('CSRF failure detected during registration; request cookies: ' . json_encode($request->cookies->all()));
                    $logger->warning('CSRF failure detected during registration; request headers: ' . json_encode(array_intersect_key($request->headers->all(), array_flip(['x-csrf-token','x-xsrf-token']))));
                }

                // Keep user-visible message simple and actionable
                $this->addFlash('error', 'There was a problem with your submission. Please try again.');
            }

            if ($hasCsrfError) {
                // Redirect to registration GET so a fresh CSRF token and cookie are issued
                return $this->redirectToRoute('app_register');
            }
        }

        return $this->render('auth/register.html.twig', ['form' => $form]);
    }

    #[Route('/verify-email/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(string $token, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->findOneBy(['emailVerificationToken' => $token]);

        if (!$user) {
            $this->addFlash('error', 'Invalid verification link.');
            return $this->redirectToRoute('app_login');
        }

        if ($user->isEmailVerified()) {
            $this->addFlash('info', 'Email already verified.');
            return $this->redirectToRoute('app_login');
        }

        $user->setIsEmailVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());

        $em->flush();

        $this->addFlash('success', 'Email verified successfully! You can now login.');
        return $this->redirectToRoute('app_login');
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, ?CsrfTokenManagerInterface $csrfTokenManager = null): Response
    {
        $user = $this->getUser();

        // If already logged in, redirect to home — but if logged in and email isn't verified,
        // show the verification pending page so the user can complete the process.
        if ($user) {
            if ($user instanceof User && !$user->isEmailVerified()) {
                return $this->render('auth/verify_pending.html.twig');
            }
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        // Generate CSRF token value if a token manager is available; avoid calling twig csrf_token()
        $csrfTokenValue = null;
        if (null !== $csrfTokenManager) {
            try {
                $csrfTokenValue = $csrfTokenManager->getToken('authenticate')->getValue();
            } catch (\Throwable $e) {
                // leave null if unable to generate
            }
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'csrf_token' => $csrfTokenValue,
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): void
    {
    }

    private function sendVerificationEmail(User $user, MailerInterface $mailer, LoggerInterface $logger): void
    {
        $verificationUrl = $this->generateUrl('app_verify_email', [
            'token' => $user->getEmailVerificationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->from('noreply@orbit-forum.com')
            ->to($user->getEmail())
            ->subject('Verify your Orbit Forum email')
            ->html(sprintf(
                '<p>Welcome to Orbit Forum!</p>
                <p>Please verify your email by clicking the link below:</p>
                <a href="%s">Verify Email</a>
                <p>If you did not create this account, please ignore this email.</p>',
                $verificationUrl
            ));

        try {
            $mailer->send($email);
        } catch (\Throwable $e) {
            // log failure so it can be inspected in logs; keep user flow intact
            $logger->error('Verification email failed: ' . $e->getMessage());
        }
    }
}
