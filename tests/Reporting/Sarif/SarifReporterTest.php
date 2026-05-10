<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Reporting\Sarif;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Reporting\Sarif\SarifReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class SarifReporterTest extends TestCase
{
    private const ROOT_PATH = '/abs/root/project';

    private function makeContext(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      self::ROOT_PATH,
            consoleBinary: null,
            composerData:  ['name' => 'acme/symfony-app'],
            sourcePaths:   [self::ROOT_PATH . '/src'],
        );
    }

    private function renderToArray(FindingBag $bag): array
    {
        $reporter = new SarifReporter();
        $output   = new BufferedOutput();
        $score    = Score::fromBag($bag);
        $ctx      = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $json = $output->fetch();
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function makeBagWithThreeFindings(): FindingBag
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'common.security.hardcoded-secrets',
            severity: Severity::Critical,
            category: Category::Security,
            message:  'Hardcoded secret found',
            file:     self::ROOT_PATH . '/src/Foo.php',
            line:     12,
        ));
        $bag->add(new Finding(
            ruleId:   'symfony.performance.missing-cache',
            severity: Severity::Medium,
            category: Category::Performance,
            message:  'Cache not configured',
            file:     self::ROOT_PATH . '/src/Bar.php',
            line:     5,
        ));
        $bag->add(new Finding(
            ruleId:   'common.hygiene.env-desync',
            severity: Severity::Info,
            category: Category::Hygiene,
            message:  'Key missing from .env.example',
            file:     null,
            line:     null,
        ));
        return $bag;
    }

    public function testProducesValidSarifJson(): void
    {
        $bag  = $this->makeBagWithThreeFindings();
        $data = $this->renderToArray($bag);

        // Top-level required keys.
        $this->assertSame('2.1.0', $data['version']);
        $this->assertArrayHasKey('$schema', $data);
        $this->assertArrayHasKey('runs', $data);
        $this->assertCount(1, $data['runs']);

        $run = $data['runs'][0];
        $this->assertArrayHasKey('tool', $run);
        $this->assertArrayHasKey('results', $run);
        $this->assertSame('php-doctor', $run['tool']['driver']['name']);

        // First result shape.
        $firstResult = $run['results'][0];
        $this->assertArrayHasKey('ruleId', $firstResult);
        $this->assertArrayHasKey('level', $firstResult);
        $this->assertArrayHasKey('message', $firstResult);
        $this->assertArrayHasKey('text', $firstResult['message']);
    }

    public function testSeverityMapping(): void
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'rule.critical',
            severity: Severity::Critical,
            category: Category::Security,
            message:  'Critical finding',
            file:     null,
            line:     null,
        ));
        $bag->add(new Finding(
            ruleId:   'rule.medium',
            severity: Severity::Medium,
            category: Category::Security,
            message:  'Medium finding',
            file:     null,
            line:     null,
        ));
        $bag->add(new Finding(
            ruleId:   'rule.info',
            severity: Severity::Info,
            category: Category::Security,
            message:  'Info finding',
            file:     null,
            line:     null,
        ));

        $data    = $this->renderToArray($bag);
        $results = $data['runs'][0]['results'];

        $levelByRule = [];
        foreach ($results as $result) {
            $levelByRule[$result['ruleId']] = $result['level'];
        }

        $this->assertSame('error',   $levelByRule['rule.critical']);
        $this->assertSame('warning', $levelByRule['rule.medium']);
        $this->assertSame('note',    $levelByRule['rule.info']);
    }

    public function testHighSeverityMapsToError(): void
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'rule.high',
            severity: Severity::High,
            category: Category::Security,
            message:  'High finding',
            file:     null,
            line:     null,
        ));

        $data   = $this->renderToArray($bag);
        $result = $data['runs'][0]['results'][0];

        $this->assertSame('error', $result['level']);
    }

    public function testLowSeverityMapsToNote(): void
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'rule.low',
            severity: Severity::Low,
            category: Category::Security,
            message:  'Low finding',
            file:     null,
            line:     null,
        ));

        $data   = $this->renderToArray($bag);
        $result = $data['runs'][0]['results'][0];

        $this->assertSame('note', $result['level']);
    }

    public function testRulesDeduplicated(): void
    {
        $bag = new FindingBag();
        // Three findings with the same ruleId.
        for ($i = 0; $i < 3; $i++) {
            $bag->add(new Finding(
                ruleId:   'common.security.hardcoded-secrets',
                severity: Severity::High,
                category: Category::Security,
                message:  "Finding #{$i}",
                file:     null,
                line:     null,
            ));
        }

        $data  = $this->renderToArray($bag);
        $rules = $data['runs'][0]['tool']['driver']['rules'];

        // Despite 3 findings, only 1 unique rule entry.
        $this->assertCount(1, $rules);
        $this->assertSame('common.security.hardcoded-secrets', $rules[0]['id']);
    }

    public function testRelativeUriPath(): void
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'rule.test',
            severity: Severity::High,
            category: Category::Security,
            message:  'Test finding',
            file:     self::ROOT_PATH . '/src/Foo.php',
            line:     42,
        ));

        $data    = $this->renderToArray($bag);
        $result  = $data['runs'][0]['results'][0];

        $uri = $result['locations'][0]['physicalLocation']['artifactLocation']['uri'];

        // Must be relative, no leading slash, no absolute root.
        $this->assertSame('src/Foo.php', $uri);
        $this->assertStringNotContainsString(self::ROOT_PATH, $uri);
        $this->assertDoesNotMatchRegularExpression('/^\//', $uri);
    }

    public function testOutOfTreePathIsRedacted(): void
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'rule.test',
            severity: Severity::High,
            category: Category::Security,
            message:  'Out-of-tree finding',
            file:     '/etc/passwd',
            line:     1,
        ));

        $data   = $this->renderToArray($bag);
        $result = $data['runs'][0]['results'][0];

        $uri = $result['locations'][0]['physicalLocation']['artifactLocation']['uri'];

        // Must NOT contain the absolute path or any directory prefix.
        $this->assertStringNotContainsString('/etc', $uri);
        $this->assertStringNotContainsString('/etc/passwd', $uri);
        // Must only be the basename.
        $this->assertSame('passwd', $uri);
        // Must carry the out-of-tree marker in the property bag.
        $this->assertTrue($result['locations'][0]['physicalLocation']['properties']['outOfTreePath']);
    }

    public function testEmptyBagProducesEmptyResults(): void
    {
        $bag  = new FindingBag();
        $data = $this->renderToArray($bag);

        $this->assertSame([], $data['runs'][0]['results']);
        $this->assertSame([], $data['runs'][0]['tool']['driver']['rules']);
    }
}
