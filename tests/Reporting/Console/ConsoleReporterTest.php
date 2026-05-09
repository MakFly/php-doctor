<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Reporting\Console;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Reporting\Console\ConsoleReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConsoleReporterTest extends TestCase
{
    private function makeContext(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      '/tmp/test-project',
            consoleBinary: null,
            composerData:  ['name' => 'acme/my-project'],
            sourcePaths:   ['/tmp/test-project/src'],
        );
    }

    private function makeBag(): FindingBag
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'test.security.critical',
            severity: Severity::Critical,
            category: Category::Security,
            message:  'Critical security issue found',
            file:     '/tmp/test-project/src/Foo.php',
            line:     42,
            fixHint:  'Fix it immediately',
        ));
        $bag->add(new Finding(
            ruleId:   'test.performance.medium',
            severity: Severity::Medium,
            category: Category::Performance,
            message:  'Medium performance issue',
            file:     '/tmp/test-project/src/Bar.php',
            line:     7,
        ));
        $bag->add(new Finding(
            ruleId:   'test.hygiene.info',
            severity: Severity::Info,
            category: Category::Hygiene,
            message:  'Informational finding',
            file:     null,
            line:     null,
        ));
        return $bag;
    }

    public function testRenderContainsProjectName(): void
    {
        $reporter = new ConsoleReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = $output->fetch();
        $this->assertStringContainsString('acme/my-project', $content);
    }

    public function testRenderContainsGlobalScore(): void
    {
        $reporter = new ConsoleReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = $output->fetch();
        // Global score must appear as "X/100" somewhere in the output.
        $this->assertMatchesRegularExpression('/\d+\/100/', $content);
    }

    public function testRenderContainsAllThreeFindings(): void
    {
        $reporter = new ConsoleReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = $output->fetch();
        $this->assertStringContainsString('test.security.critical', $content);
        $this->assertStringContainsString('test.performance.medium', $content);
        $this->assertStringContainsString('test.hygiene.info', $content);
    }

    public function testRenderReturnsZero(): void
    {
        $reporter = new ConsoleReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $exitCode = $reporter->render($bag, $score, $ctx, $output);

        $this->assertSame(0, $exitCode);
    }

    public function testRenderEmptyBagShowsCleanMessage(): void
    {
        $reporter = new ConsoleReporter();
        $output   = new BufferedOutput();
        $bag      = new FindingBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = $output->fetch();
        $this->assertStringContainsString('clean run', $content);
    }
}
