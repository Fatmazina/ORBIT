<?php
namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DevContentSecurityPolicyListener implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger, private string $env = 'dev')
    {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($this->env !== 'dev') {
            return;
        }

        $response = $event->getResponse();
        $csp = $response->headers->get('Content-Security-Policy');

        if (!$csp) {
            // If no CSP is set, add a minimal one allowing unsafe-eval for testing
            $csp = "script-src 'self' 'unsafe-eval'";
        } else {
            if (!str_contains($csp, "'unsafe-eval'")) {
                // Try to append unsafe-eval to script-src directive if present
                $csp = preg_replace_callback("/(script-src[^;]*)/i", function ($m) {
                    if (str_contains($m[1], "'unsafe-eval'")) {
                        return $m[1];
                    }
                    return $m[1] . " 'unsafe-eval'";
                }, $csp, -1, $count);

                // If no script-src directive was found, append one
                if (empty($count)) {
                    $csp .= "; script-src 'self' 'unsafe-eval'";
                }
            }
        }

        $response->headers->set('Content-Security-Policy', $csp);
        $this->logger->info('Dev CSP updated to allow unsafe-eval for testing.');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.response' => ['onKernelResponse', -100],
        ];
    }
}
