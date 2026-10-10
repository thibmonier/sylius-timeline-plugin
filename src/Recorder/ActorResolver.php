<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Recorder;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;

final class ActorResolver implements ActorResolverInterface
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function resolve(?Actor $fallback = null): Actor
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if ($user instanceof AdminUserInterface) {
            return Actor::admin($user);
        }

        if ($user instanceof ShopUserInterface) {
            return Actor::customer($user->getCustomer()?->getFullName());
        }

        return $fallback ?? Actor::system();
    }
}
