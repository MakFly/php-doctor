<?php

declare(strict_types=1);

namespace PhpDoctor\Cli;

use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Rules\RuleProvider;
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

        $registry = RuleProvider::buildRegistry(new ParserPool());

        $rows = [];
        foreach ($registry->all() as $rule) {
            $rows[] = [
                $rule->id(),
                $rule->category()->value,
                $rule->severity()->value,
            ];
        }

        $io->title('php-doctor — Available Rules');
        $io->table(['Rule ID', 'Category', 'Severity'], $rows);
        $io->note(sprintf('%d rule(s) registered.', count($rows)));

        return Command::SUCCESS;
    }
}
