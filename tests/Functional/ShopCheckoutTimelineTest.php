<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Functional;

use Sylius\Component\Addressing\Factory\ZoneFactoryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Shipping\Calculator\DefaultCalculators;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/**
 * A real shop checkout: the order confirmation e-mail is sent on `sylius.order.post_complete`,
 * after the application flush, and must still end up in the timeline.
 */
final class ShopCheckoutTimelineTest extends WebTestCase
{
    use TimelineTestTrait;

    private const SHIPPING_METHOD_CODE = 'TIMELINE_TEST_SHIPPING';

    private const PAYMENT_METHOD_CODE = 'TIMELINE_TEST_PAYMENT';

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
        $this->createCheckoutOptions($cart->getChannel());
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
        $this->submitStepChoosing(self::SHIPPING_METHOD_CODE);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $this->submitStepChoosing(self::PAYMENT_METHOD_CODE);
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

    /**
     * The random fixtures give every shipping method a random zone and a 10% chance of being disabled, and
     * payment methods a random enabled state: the checkout may offer no option at all (HTTP 422). The
     * test therefore creates, and explicitly selects, options that are always available.
     */
    private function createCheckoutOptions(?ChannelInterface $channel): void
    {
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $container = self::getContainer();

        /** @var ZoneFactoryInterface $zoneFactory */
        $zoneFactory = $container->get('sylius.factory.zone');
        $zone = $zoneFactory->createWithMembers(['US']);
        $zone->setCode('TIMELINE_TEST_US');
        $zone->setName('Timeline test US');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        /** @var FactoryInterface<ShippingMethodInterface> $shippingFactory */
        $shippingFactory = $container->get('sylius.factory.shipping_method');
        $shipping = $shippingFactory->createNew();
        $shipping->setCode(self::SHIPPING_METHOD_CODE);
        $shipping->setCurrentLocale('en_US');
        $shipping->setFallbackLocale('en_US');
        $shipping->setName('Timeline test shipping');
        $shipping->setEnabled(true);
        $shipping->setZone($zone);
        $shipping->setCalculator(DefaultCalculators::FLAT_RATE);
        $shipping->setConfiguration([(string) $channel->getCode() => ['amount' => 500]]);
        $shipping->addChannel($channel);

        /** @var PaymentMethodFactoryInterface $paymentFactory */
        $paymentFactory = $container->get('sylius.factory.payment_method');
        $payment = $paymentFactory->createWithGateway('offline');
        $payment->setCode(self::PAYMENT_METHOD_CODE);
        $payment->setCurrentLocale('en_US');
        $payment->setFallbackLocale('en_US');
        $payment->setName('Timeline test payment');
        $payment->setEnabled(true);
        $payment->addChannel($channel);

        $this->entityManager()->persist($zone);
        $this->entityManager()->persist($shipping);
        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();
    }

    private function submitStepChoosing(string $optionCode): void
    {
        $form = $this->client->getCrawler()->selectButton('Next')->form();
        $selected = false;
        foreach ($form->all() as $field) {
            if ($field instanceof ChoiceFormField && 'radio' === $field->getType() && in_array($optionCode, $field->availableOptionValues(), true)) {
                $field->select($optionCode);
                $selected = true;
            }
        }

        self::assertTrue($selected, sprintf('The checkout step offers the "%s" option.', $optionCode));
        $this->client->submit($form);
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
