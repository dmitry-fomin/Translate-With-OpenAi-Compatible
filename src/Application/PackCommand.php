<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\EpubRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PackCommand extends Command
{
    public function __construct(
        private readonly EpubRepositoryInterface $epubRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pack')
            ->setDescription('Pack an unpacked EPUB folder back into an .epub file')
            ->addArgument('input', InputArgument::REQUIRED, 'Path to the unpacked EPUB folder')
            ->addArgument('output', InputArgument::REQUIRED, 'Path to the resulting .epub file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = (string) $input->getArgument('input');
        $outputPath = (string) $input->getArgument('output');

        try {
            $this->epubRepository->pack($inputPath, $outputPath);
            $output->writeln("<info>Packed: $outputPath</info>");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
