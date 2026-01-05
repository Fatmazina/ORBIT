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
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' cdn.jsdelivr.net code.tidio.co; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net; img-src 'self' data: *.tidio.co; font-src 'self' cdn.jsdelivr.net; connect-src 'self' *.tidio.co";
        } else {
            // Add unsafe-inline and cdn.jsdelivr.net to script-src if it exists
            if (str_contains($csp, 'script-src')) {
                if (!str_contains($csp, "'unsafe-inline'")) {
                    $csp = str_ireplace('script-src', "script-src 'unsafe-inline'", $csp);
                }
                if (!str_contains($csp, 'cdn.jsdelivr.net')) {
                    $csp = str_ireplace('script-src', "script-src cdn.jsdelivr.net", $csp);
                }
                if (!str_contains($csp, 'code.tidio.co')) {
                    $csp = str_ireplace('script-src', "script-src code.tidio.co", $csp);
                }
            } else {
                $csp .= "; script-src 'self' 'unsafe-inline' 'unsafe-eval' cdn.jsdelivr.net code.tidio.co";
            }

            // Add connect-src for Tidio
            if (str_contains($csp, 'connect-src')) {
                if (!str_contains($csp, '*.tidio.co')) {
                    $csp = str_ireplace('connect-src', "connect-src *.tidio.co", $csp);
                }
            } else {
                $csp .= "; connect-src 'self' *.tidio.co";
            }

            // Add img-src for Tidio
            if (str_contains($csp, 'img-src')) {
                if (!str_contains($csp, '*.tidio.co')) {
                    $csp = str_ireplace('img-src', "img-src *.tidio.co", $csp);
                }
            } else {
                $csp .= "; img-src 'self' data: *.tidio.co";
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
