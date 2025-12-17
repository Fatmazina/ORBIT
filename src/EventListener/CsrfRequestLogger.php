<?php
namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CsrfRequestLogger implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->isMethod('POST')) {
            return;
        }

        $path = $request->getPathInfo();
        if (str_starts_with($path, '/auth/register') || str_starts_with($path, '/auth/login')) {
            $cookies = $request->cookies->all();
            $headers = $request->headers->all();
            $this->logger->info(sprintf('CSRF check on %s POST: cookies=%s', $path, json_encode($cookies)));
            $this->logger->info(sprintf('CSRF check on %s POST: headers=%s', $path, json_encode(array_intersect_key($headers, array_flip(['x-csrf-token','x-xsrf-token'])))));

            // Also log request body params for debugging authentication
            $this->logger->info(sprintf('Request POST params for %s: %s', $path, json_encode($request->request->all())));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => ['onKernelRequest', 0],
        ];
    }
}
