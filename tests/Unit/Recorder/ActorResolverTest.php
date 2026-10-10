<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Recorder;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\ShopUser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;
use Tmonier\SyliusTimelinePlugin\Recorder\ActorResolver;

final class ActorResolverTest extends TestCase
{
    public function testAnAdministratorIsResolvedWithANameSnapshot(): void
    {
        $admin = new AdminUser();
        $admin->setFirstName('John');
        $admin->setLastName('Doe');

        $actor = $this->resolver($admin)->resolve();

        self::assertSame(TimelineEntryInterface::AUTHOR_ADMIN, $actor->type);
        self::assertSame('John Doe', $actor->name);
        self::assertSame($admin, $actor->adminUser);
    }

    public function testAShopUserIsTheCustomer(): void
    {
        $customer = new Customer();
        $customer->setFirstName('Jane');
        $customer->setLastName('Roe');
        $user = new ShopUser();
        $user->setCustomer($customer);

        $actor = $this->resolver($user)->resolve();

        self::assertSame(TimelineEntryInterface::AUTHOR_CUSTOMER, $actor->type);
        self::assertSame('Jane Roe', $actor->name);
        self::assertNull($actor->adminUser);
    }

    public function testWithoutAuthenticatedUserTheFallbackIsUsed(): void
    {
        self::assertTrue($this->resolver(null)->resolve()->isSystem());
        self::assertSame(TimelineEntryInterface::AUTHOR_CUSTOMER, $this->resolver(null)->resolve(Actor::customer())->type);
    }

    private function resolver(?UserInterface $user): ActorResolver
    {
        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }

        return new ActorResolver($tokenStorage);
    }
}
