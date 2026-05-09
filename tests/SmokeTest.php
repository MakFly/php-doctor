<?php

declare(strict_types=1);

namespace PhpDoctor\Tests;

use PhpDoctor\Cli\ListRulesCommand;
use PhpDoctor\Cli\ScanCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class SmokeTest extends TestCase
{
    public function testScanCommandClassExists(): void
    {
        $this->assertTrue(class_exists(ScanCommand::class));
    }

    public function testListRulesCommandClassExists(): void
    {
        $this->assertTrue(class_exists(ListRulesCommand::class));
    }

    public function testScanCommandInstantiates(): void
    {
        $cmd = new ScanCommand();
        $this->assertInstanceOf(Command::class, $cmd);
        $this->assertSame('scan', $cmd->getName());
    }

    public function testListRulesCommandInstantiates(): void
    {
        $cmd = new ListRulesCommand();
        $this->assertInstanceOf(Command::class, $cmd);
        $this->assertSame('list-rules', $cmd->getName());
    }
}
