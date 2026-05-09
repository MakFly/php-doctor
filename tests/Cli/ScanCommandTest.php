<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Cli;

use PhpDoctor\Cli\ScanCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanCommandTest extends TestCase
{
    private const SYMFONY_FIXTURE = __DIR__ . '/../fixtures/projects/symfony-min';

    private function makeCommandTester(): CommandTester
    {
        $app     = new Application('php-doctor', '0.1.0-dev');
        $command = new ScanCommand();
        $app->add($command);

        return new CommandTester($app->find('scan'));
    }

    public function testScanJsonOutputIsParseable(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute([
            'path'     => self::SYMFONY_FIXTURE,
            '--format' => 'json',
        ]);

        $output = $tester->getDisplay();

        // Must be valid JSON — throws on failure.
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data, 'JSON output must decode to an array');
    }

    public function testScanJsonSchemaVersion(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute([
            'path'     => self::SYMFONY_FIXTURE,
            '--format' => 'json',
        ]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1', $data['version']);
    }

    public function testScanJsonHasRequiredTopLevelKeys(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute([
            'path'     => self::SYMFONY_FIXTURE,
            '--format' => 'json',
        ]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('tool', $data);
        $this->assertArrayHasKey('project', $data);
        $this->assertArrayHasKey('score', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('findings', $data);
    }

    public function testScanJsonScoreIsInteger(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute([
            'path'     => self::SYMFONY_FIXTURE,
            '--format' => 'json',
        ]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsInt($data['score']['global']);
        $this->assertGreaterThanOrEqual(0, $data['score']['global']);
        $this->assertLessThanOrEqual(100, $data['score']['global']);
    }

    public function testScanJsonProjectFrameworkIsSymfony(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute([
            'path'     => self::SYMFONY_FIXTURE,
            '--format' => 'json',
        ]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('symfony', $data['project']['framework']);
    }

    public function testScanInvalidFormatReturnsFailure(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute([
            'path'     => self::SYMFONY_FIXTURE,
            '--format' => 'invalid-format',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testScanConsoleFormatReturnsSuccess(): void
    {
        $tester = $this->makeCommandTester();
        $tester->execute([
            'path'     => self::SYMFONY_FIXTURE,
            '--format' => 'console',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
