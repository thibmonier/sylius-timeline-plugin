<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusTimelinePlugin\Backfill\TimelineBackfillerInterface;

#[AsCommand(
    name: 'tmonier:timeline:backfill',
    description: 'Creates timeline entries for placed orders that have none (orders placed before the plugin was installed).',
)]
final class BackfillTimelineCommand extends Command
{
    public function __construct(
        private readonly TimelineBackfillerInterface $backfiller,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the orders and entries, write nothing')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Orders per batch (flush + clear)', '100')
            ->setHelp(<<<'HELP'
                The command only touches orders that have <comment>no timeline entry at all</comment>, so it
                can be run again safely. It rebuilds what the order still tells: placed (with the
                customer's checkout note), completed payments, shipped shipments and cancellation.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = $input->getOption('batch-size');
        $batchSize = is_numeric($batchSize) ? max(1, (int) $batchSize) : 100;

        $total = $this->backfiller->countOrdersWithoutEntries();
        if (0 === $total) {
            $io->success('Every placed order already has a timeline.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d placed order(s) without timeline entries%s.', $total, $dryRun ? ' (dry run)' : ''));
        $io->progressStart($total);

        $orderCount = 0;
        $entryCount = 0;
        $lastId = 0;
        while ([] !== $orders = $this->backfiller->findOrdersWithoutEntries($lastId, $batchSize)) {
            foreach ($orders as $order) {
                $id = $order->getId();
                $lastId = is_int($id) ? $id : $lastId;
                foreach ($this->backfiller->createEntries($order) as $entry) {
                    ++$entryCount;
                    if (!$dryRun) {
                        $this->entityManager->persist($entry);
                    }
                }
                ++$orderCount;
                $io->progressAdvance();
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
            $this->entityManager->clear();
        }

        $io->progressFinish();
        $io->success(sprintf(
            '%s %d entr%s for %d order(s).',
            $dryRun ? 'Would create' : 'Created',
            $entryCount,
            1 === $entryCount ? 'y' : 'ies',
            $orderCount,
        ));

        return Command::SUCCESS;
    }
}
