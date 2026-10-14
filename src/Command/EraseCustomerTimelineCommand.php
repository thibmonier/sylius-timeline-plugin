<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Gdpr\CustomerTimelineEraserInterface;

/**
 * Erases personal data of a customer from their timeline entries.
 *
 * Usage:
 *   tmonier:timeline:erase-customer <id-or-email> [--dry-run]
 *
 * Intended to be called as part of your application's GDPR anonymisation process.
 * See README for integration guidance.
 */
#[AsCommand(
    name: 'tmonier:timeline:erase-customer',
    description: 'Erases personal data (e-mail addresses, name snapshots, checkout notes) of a customer from their timeline entries.',
)]
final class EraseCustomerTimelineCommand extends Command
{
    /**
     * @param class-string $customerClass resolved from %sylius.model.customer.class%
     * @param class-string $entityClass   resolved from %tmonier_timeline.model.timeline_entry.class%
     */
    public function __construct(
        private readonly CustomerTimelineEraserInterface $eraser,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $customerClass,
        private readonly string $entityClass,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('customer', InputArgument::REQUIRED, 'Customer ID (integer) or e-mail address')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be erased without writing anything')
            ->setHelp(<<<'HELP'
                Removes personal data stored in timeline entries for a specific customer:

                  - e-mail recipient addresses (context.recipients of email entries)
                  - customer full-name snapshots (author_name of order_placed / customer_note entries)
                  - checkout notes written by the customer (content of customer_note entries)

                Business records (entry type, transition, date, order number, amounts…) are
                preserved. Staff notes are not erased — they are authored by administrators.

                Use <comment>--dry-run</comment> to preview the affected entries without persisting anything.

                <info>Example</info>
                  <comment>bin/console tmonier:timeline:erase-customer 42</comment>
                  <comment>bin/console tmonier:timeline:erase-customer jane@example.com --dry-run</comment>
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var string $customerArg */
        $customerArg = $input->getArgument('customer');

        $customer = $this->findCustomer($customerArg);

        if (null === $customer) {
            $io->error(sprintf('No customer found for "%s".', $customerArg));

            return Command::FAILURE;
        }

        $customerId = $customer->getId();
        $io->title(sprintf('Erasing timeline personal data for customer #%s (%s)', is_scalar($customerId) ? (string) $customerId : 'N/A', $customer->getEmail() ?? '—'));

        if ($dryRun) {
            $count = $this->countAffectedEntries($customer);
            $io->note(sprintf('[dry-run] Would erase personal data from %d timeline entry(ies). No changes written.', $count));

            return Command::SUCCESS;
        }

        $count = $this->eraser->eraseForCustomer($customer);

        $io->success(sprintf('Personal data erased from %d timeline entry(ies).', $count));

        return Command::SUCCESS;
    }

    private function findCustomer(string $idOrEmail): ?CustomerInterface
    {
        $repository = $this->entityManager->getRepository($this->customerClass);

        if (is_numeric($idOrEmail)) {
            /** @var CustomerInterface|null $customer */
            $customer = $repository->find((int) $idOrEmail);

            return $customer;
        }

        /** @var CustomerInterface|null $customer */
        $customer = $repository->findOneBy(['email' => $idOrEmail]);

        return $customer;
    }

    /**
     * Counts entries that would be affected by erasure without touching any data.
     * Used for --dry-run only.
     */
    private function countAffectedEntries(CustomerInterface $customer): int
    {
        /** @var string|int $count */
        $count = $this->entityManager
            ->createQuery(sprintf(
                'SELECT COUNT(e.id) FROM %s e
                 WHERE e.customer = :customer
                   AND (e.authorType = :authorType OR e.type = :emailType)',
                $this->entityClass,
            ))
            ->setParameter('customer', $customer)
            ->setParameter('authorType', TimelineEntryInterface::AUTHOR_CUSTOMER)
            ->setParameter('emailType', TimelineEntryInterface::TYPE_EMAIL)
            ->getSingleScalarResult()
        ;

        return (int) $count;
    }
}
