<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Functional;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/**
 * A real shop checkout: the order confirmation e-mail is sent on `sylius.order.post_complete`,
 * after the application flush, and must still end up in the timeline.
 */
final class ShopCheckoutTimelineTest extends WebTestCase
{
    use TimelineTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClient();
        parent::tearDown();
    }

    public function testPlacingAnOrderInTheShopRecordsTheOrderAndTheConfirmationEmail(): void
    {
        $shopUser = $this->entityManager()->getRepository(ShopUserInterface::class)->findOneBy(['enabled' => true]);
        self::assertInstanceOf(ShopUserInterface::class, $shopUser);
        $cart = $this->createCart($shopUser);
        $this->client->loginUser($shopUser, 'shop');

        $this->client->request('GET', '/en_US/checkout/address');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Next', [
            'sylius_shop_checkout_address[billingAddress][firstName]' => 'Jane',
            'sylius_shop_checkout_address[billingAddress][lastName]' => 'Doe',
            'sylius_shop_checkout_address[billingAddress][street]' => '1 Test Street',
            'sylius_shop_checkout_address[billingAddress][countryCode]' => 'US',
            'sylius_shop_checkout_address[billingAddress][city]' => 'Springfield',
            'sylius_shop_checkout_address[billingAddress][postcode]' => '12345',
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $this->client->submitForm('Next');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $this->client->submitForm('Next');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $this->client->submitForm('Place order');
        self::assertResponseRedirects();

        $this->entityManager()->clear();
        $order = $this->entityManager()->getRepository(OrderInterface::class)->find($this->idOf($cart));
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertSame(OrderInterface::STATE_NEW, $order->getState(), 'The checkout is complete.');

        self::assertCount(1, $this->findEntries($order, TimelineEntryInterface::TYPE_ORDER_PLACED));
        $emails = $this->findEntries($order, TimelineEntryInterface::TYPE_EMAIL);
        self::assertCount(1, $emails, 'The order confirmation sent after the checkout flush is recorded.');
        self::assertSame('order_confirmation', $emails[0]->getContextValue('code'));
        self::assertSame(TimelineEntryInterface::AUTHOR_CUSTOMER, $emails[0]->getAuthorType());
    }

    private function createCart(ShopUserInterface $shopUser): OrderInterface
    {
        $container = self::getContainer();

        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['code' => 'FASHION_WEB']);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = $this->entityManager()->getRepository(ProductVariantInterface::class)->findOneBy(['enabled' => true, 'tracked' => false]);
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $container->get('sylius.factory.order');
        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = $container->get('sylius.factory.order_item');
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $container->get('sylius.modifier.order_item_quantity');
        /** @var OrderProcessorInterface $orderProcessor */
        $orderProcessor = $container->get('sylius.order_processing.order_processor');

        $cart = $orderFactory->createNew();
        $cart->setChannel($channel);
        $cart->setLocaleCode('en_US');
        $cart->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
        $cart->setCustomerWithAuthorization($shopUser->getCustomer());

        $item = $itemFactory->createNew();
        $item->setVariant($variant);
        $quantityModifier->modify($item, 1);
        $cart->addItem($item);
        $orderProcessor->process($cart);

        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();

        return $cart;
    }
}
