<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Reporting\Json;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Reporting\Json\JsonReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class JsonReporterTest extends TestCase
{
    private function makeContext(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      '/tmp/laravel-project',
            consoleBinary: null,
            composerData:  ['name' => 'acme/laravel-app'],
            sourcePaths:   ['/tmp/laravel-project/app'],
        );
    }

    private function makeBag(): FindingBag
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'laravel.security.mass-assignment',
            severity: Severity::High,
            category: Category::Security,
            message:  'Model missing $fillable or $guarded',
            file:     '/tmp/laravel-project/app/Models/User.php',
            line:     10,
            fixHint:  'Add $fillable property',
        ));
        $bag->add(new Finding(
            ruleId:   'common.hygiene.env-desync',
            severity: Severity::Medium,
            category: Category::Hygiene,
            message:  'Key missing from .env.example',
            file:     null,
            line:     null,
        ));
        return $bag;
    }

    public function testJsonSchemaVersion(): void
    {
        $reporter = new JsonReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $json = $output->fetch();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('1', $data['version']);
    }

    public function testJsonToolShape(): void
    {
        $reporter = new JsonReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $json = $output->fetch();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('tool', $data);
        $this->assertSame('php-doctor', $data['tool']['name']);
        $this->assertArrayHasKey('version', $data['tool']);
    }

    public function testJsonScoreGlobal(): void
    {
        $reporter = new JsonReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $json = $output->fetch();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('score', $data);
        $this->assertArrayHasKey('global', $data['score']);
        $this->assertIsInt($data['score']['global']);
        $this->assertSame($score->global, $data['score']['global']);
    }

    public function testJsonFindingsShape(): void
    {
        $reporter = new JsonReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $json = $output->fetch();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('findings', $data);
        $this->assertCount(2, $data['findings']);

        $first = $data['findings'][0];
        $this->assertArrayHasKey('ruleId', $first);
        $this->assertArrayHasKey('severity', $first);
        $this->assertArrayHasKey('category', $first);
        $this->assertArrayHasKey('message', $first);
        $this->assertArrayHasKey('file', $first);
        $this->assertArrayHasKey('line', $first);
        $this->assertArrayHasKey('fixHint', $first);
        $this->assertArrayHasKey('docUrl', $first);

        // Verify rule IDs are preserved correctly.
        $ruleIds = array_column($data['findings'], 'ruleId');
        $this->assertContains('laravel.security.mass-assignment', $ruleIds);
        $this->assertContains('common.hygiene.env-desync', $ruleIds);
    }

    public function testJsonSummaryShape(): void
    {
        $reporter = new JsonReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $json = $output->fetch();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('summary', $data);
        $this->assertSame(2, $data['summary']['totalFindings']);
        $this->assertArrayHasKey('bySeverity', $data['summary']);
        $this->assertSame(1, $data['summary']['bySeverity']['high']);
        $this->assertSame(1, $data['summary']['bySeverity']['medium']);
        $this->assertSame(0, $data['summary']['bySeverity']['critical']);
    }

    public function testJsonProjectShape(): void
    {
        $reporter = new JsonReporter();
        $output   = new BufferedOutput();
        $bag      = $this->makeBag();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $json = $output->fetch();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('project', $data);
        $this->assertSame('laravel', $data['project']['framework']);
        $this->assertSame('acme/laravel-app', $data['project']['name']);
        $this->assertSame('/tmp/laravel-project', $data['project']['rootPath']);
    }
}
