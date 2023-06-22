<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContextAwareInterface;

readonly class HostAwareRouteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestContextAwareInterface $router,
        private string $appUrl
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onRequest'];
    }

    public function onRequest(RequestEvent $event): void
    {
        $this->router->getContext()->setHost($this->appUrl);
        $this->router->getContext()->setHttpsPort(443);
        $this->router->getContext()->setHttpPort(80);
        $this->router->getContext()->setScheme('https');
    }
}
