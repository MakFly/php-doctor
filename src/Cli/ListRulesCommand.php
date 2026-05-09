<?php

declare(strict_types=1);

namespace PhpDoctor\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'list-rules',
    description: 'List all available analysis rules.',
)]
final class ListRulesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->note('list-rules stub — not yet implemented.');

        return Command::SUCCESS;
    }
}
