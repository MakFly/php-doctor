<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Common;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Common\EnvDesyncRule;
use PHPUnit\Framework\TestCase;

final class EnvDesyncRuleTest extends TestCase
{
    private string $tmpDir;
    private EnvDesyncRule $rule;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdoctor_envtest_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->rule = new EnvDesyncRule();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function makeInput(): AnalysisInput
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      $this->tmpDir,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
        return new AnalysisInput($ctx);
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testDetectsKeyInExampleMissingFromEnv(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "APP_KEY=\nDB_PASSWORD=\nNEW_KEY=\n");
        file_put_contents($this->tmpDir . '/.env',         "APP_KEY=actual\nDB_PASSWORD=secret\n");

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('NEW_KEY', $findings[0]->message);
        $this->assertStringContainsString('.env.example', $findings[0]->message);
        $this->assertSame('common.hygiene.env-desync', $findings[0]->ruleId);
        $this->assertSame(Severity::Medium, $findings[0]->severity);
        $this->assertSame(Category::Hygiene, $findings[0]->category);
    }

    public function testDetectsKeyInEnvMissingFromExample(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "APP_KEY=\n");
        file_put_contents($this->tmpDir . '/.env',         "APP_KEY=actual\nSECRET_KEY=hidden\n");

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('SECRET_KEY', $findings[0]->message);
        $this->assertStringContainsString('.env but not in .env.example', $findings[0]->message);
    }

    public function testDetectsMultipleMissingKeys(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "APP_KEY=\nA=\nB=\nC=\n");
        file_put_contents($this->tmpDir . '/.env',         "APP_KEY=x\n");

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertCount(3, $findings);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testNoFindingsWhenKeysMatch(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "APP_KEY=\nDB_HOST=\n");
        file_put_contents($this->tmpDir . '/.env',         "APP_KEY=actual\nDB_HOST=localhost\n");

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertEmpty($findings);
    }

    public function testSkipsWhenNeitherFileExists(): void
    {
        // Neither .env nor .env.example
        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertEmpty($findings);
    }

    public function testIgnoresCommentLines(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "# this is a comment\nAPP_KEY=\n");
        file_put_contents($this->tmpDir . '/.env',         "APP_KEY=actual\n");

        $findings = iterator_to_array($this->rule->analyze($this->makeInput()));

        $this->assertEmpty($findings);
    }
}
