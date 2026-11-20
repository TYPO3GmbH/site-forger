<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Subscriber;

use App\Elastic\Users;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;

/**
 * Notes every login with the TYPO3 account in the index "user", see Users::recordLogin(): the username of
 * my.typo3.org is the Forge login and the Gerrit username, its email gives the avatar to those Forge shows none of.
 * A login never fails for it: without the index, the person is noted at the next login.
 */
final readonly class Typo3LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Users $users,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof KeyCloakUser) {
            return;
        }
        try {
            $this->users->recordLogin($user->getUserIdentifier(), (string) $user->getEmail(), new \DateTimeImmutable());
        } catch (\Exception $e) {
            $this->logger->warning('The login could not be noted in the users: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
