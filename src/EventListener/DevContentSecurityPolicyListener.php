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
        
        // In dev mode, we want to allow everything needed for the profiler and our charts
        // If we have an existing CSP, we append our requirements, otherwise we set a permissive one
        $csp = $response->headers->get('Content-Security-Policy');

        if (!$csp) {
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' cdn.jsdelivr.net";
        } else {
            // Add unsafe-inline and cdn.jsdelivr.net to script-src if it exists
            if (str_contains($csp, 'script-src')) {
                if (!str_contains($csp, "'unsafe-inline'")) {
                    $csp = str_ireplace('script-src', "script-src 'unsafe-inline'", $csp);
                }
                if (!str_contains($csp, 'cdn.jsdelivr.net')) {
                    $csp = str_ireplace('script-src', "script-src cdn.jsdelivr.net", $csp);
                }
            } else {
                $csp .= "; script-src 'self' 'unsafe-inline' 'unsafe-eval' cdn.jsdelivr.net";
            }

            // Add unsafe-inline and cdn.jsdelivr.net to style-src if it exists
            if (str_contains($csp, 'style-src')) {
                if (!str_contains($csp, "'unsafe-inline'")) {
                    $csp = str_ireplace('style-src', "style-src 'unsafe-inline'", $csp);
                }
                if (!str_contains($csp, 'cdn.jsdelivr.net')) {
                    $csp = str_ireplace('style-src', "style-src cdn.jsdelivr.net", $csp);
                }
            } else {
                $csp .= "; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net";
            }
        }

        $response->headers->set('Content-Security-Policy', $csp);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.response' => ['onKernelResponse', -100],
        ];
    }
}
