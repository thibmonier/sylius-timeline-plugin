<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Core\OrderShippingTransitions;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Component\Yaml\Yaml;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorder;

final class TranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pl'];

    private const DOMAINS = ['messages', 'flashes'];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                yield sprintf('%s.%s', $domain, $locale) => [$domain, $locale];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testEveryLocaleDefinesTheSameKeys(string $domain, string $locale): void
    {
        $reference = $this->flatten($this->parse($domain, 'en'));
        $catalogue = $this->flatten($this->parse($domain, $locale));

        self::assertSame([], array_values(array_diff(array_keys($reference), array_keys($catalogue))), 'Missing keys');
        self::assertSame([], array_values(array_diff(array_keys($catalogue), array_keys($reference))), 'Unknown keys');
        foreach ($catalogue as $key => $value) {
            self::assertNotSame('', trim($value), sprintf('Empty translation "%s"', $key));
            preg_match_all('/%\w+%/', $reference[$key], $expected);
            preg_match_all('/%\w+%/', $value, $actual);
            self::assertEqualsCanonicalizing($expected[0], $actual[0], sprintf('Placeholders of "%s"', $key));
        }
    }

    public function testEveryRecordedTransitionOfTheSyliusWorkflowsHasALabel(): void
    {
        $messages = $this->flatten($this->parse('messages', 'en'));
        $transitionClasses = [
            OrderTransitions::GRAPH => OrderTransitions::class,
            OrderPaymentTransitions::GRAPH => OrderPaymentTransitions::class,
            OrderShippingTransitions::GRAPH => OrderShippingTransitions::class,
            PaymentTransitions::GRAPH => PaymentTransitions::class,
            ShipmentTransitions::GRAPH => ShipmentTransitions::class,
        ];

        foreach ($transitionClasses as $workflow => $class) {
            foreach ((new \ReflectionClass($class))->getConstants() as $name => $transition) {
                if (!str_starts_with($name, 'TRANSITION_') || !is_string($transition)) {
                    continue;
                }
                if (in_array($transition, TimelineRecorder::IGNORED_TRANSITIONS[$workflow] ?? [], true)) {
                    continue;
                }
                if (OrderTransitions::GRAPH === $workflow && OrderTransitions::TRANSITION_CREATE === $transition) {
                    continue; // "Order placed"
                }

                self::assertArrayHasKey(sprintf('tmonier_timeline.event.%s.%s', $workflow, $transition), $messages);
            }
        }
    }

    /** @return array<mixed> */
    private function parse(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/../../translations/%s.%s.yaml', __DIR__, $domain, $locale));
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix . $key . '.');

                continue;
            }

            $flat[$prefix . $key] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }
}
