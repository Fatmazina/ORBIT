<?php
namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        $passport = $event->getPassport();
        $attributes = [];
        if ($passport) {
            foreach ($passport->getAttributes() as $k => $v) {
                $attributes[$k] = is_scalar($v) ? $v : get_class($v);
            }
        }

        $this->logger->warning('Login failure: ' . $exception->getMessage(), ['attributes' => $attributes]);
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();

        // Safely extract a stable identifier for logging. Prefer modern UserInterface method
        // `getUserIdentifier()` and fall back to `getId()` or class name. Catch any exception to
        // avoid breaking the login flow if user object is unexpected.
        try {
            if (is_object($user)) {
                if (method_exists($user, 'getUserIdentifier')) {
                    $identifier = $user->getUserIdentifier();
                } elseif (method_exists($user, 'getId')) {
                    $identifier = $user->getId();
                } else {
                    $identifier = get_class($user);
                }
            } else {
                $identifier = (string) $user;
            }
        } catch (\Throwable $e) {
            $identifier = 'unknown';
            $this->logger->error('Failed to extract user identifier on login success: ' . $e->getMessage());
        }

        $this->logger->info('Login success: user=' . $identifier);
    }
}
