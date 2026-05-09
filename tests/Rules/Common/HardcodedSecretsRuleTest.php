<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Common;

use PhpDoctor\Analysis\Ast\AstSnapshot;
use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Common\HardcodedSecretsRule;
use PhpParser\ErrorHandler\Collecting;
use PHPUnit\Framework\TestCase;

final class HardcodedSecretsRuleTest extends TestCase
{
    private ParserPool $parserPool;
    private HardcodedSecretsRule $rule;

    protected function setUp(): void
    {
        $this->parserPool = new ParserPool();
        $this->rule       = new HardcodedSecretsRule($this->parserPool);
    }

    private function makeInput(string $code): AnalysisInput
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $snapshot = new AstSnapshot($this->parserPool, new Collecting());
        // Inject code into a temp file so AstSnapshot can parse it
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpdoctor_test_') . '.php';
        file_put_contents($tmpFile, $code);
        $snapshot->forFile($tmpFile);

        // Return AnalysisInput
        $input = new AnalysisInput($ctx, $snapshot);

        // Cleanup registered
        register_shutdown_function(fn () => @unlink($tmpFile));

        return $input;
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testDetectsAwsAccessKey(): void
    {
        $input = $this->makeInput('<?php $key = "AKIAIOSFODNN7EXAMPLE";');
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings);
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
        $this->assertSame(Severity::Critical, $findings[0]->severity);
        $this->assertSame(Category::Security, $findings[0]->category);
    }

    public function testDetectsGithubToken(): void
    {
        $token = 'ghp_' . str_repeat('A', 36);
        $input = $this->makeInput("<?php \$t = \"{$token}\";");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings);
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    public function testDetectsJwt(): void
    {
        // A JWT has 3 base64url segments separated by dots
        $header  = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
        $payload = 'eyJzdWIiOiIxMjM0NTY3ODkwIn0';
        $sig     = 'SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $jwt     = "{$header}.{$payload}.{$sig}";
        $input   = $this->makeInput("<?php \$tok = \"{$jwt}\";");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings);
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    public function testDetectsDefineWithSecretKey(): void
    {
        $input = $this->makeInput("<?php define('API_KEY', 'super-secret-value-here');");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings);
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    public function testDetectsPutenvWithSecretKey(): void
    {
        $input = $this->makeInput("<?php putenv('PASSWORD=myrealpassword123');");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings);
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testDoesNotFlagPlaceholderInDefine(): void
    {
        $input = $this->makeInput("<?php define('API_KEY', 'changeme');");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagEmptyStringInDefine(): void
    {
        $input = $this->makeInput("<?php define('API_KEY', '');");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagNormalString(): void
    {
        $input = $this->makeInput("<?php \$x = 'Hello World, nothing secret here';");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings);
    }

    public function testDoesNotFlagDefineWithNonSecretKey(): void
    {
        $input = $this->makeInput("<?php define('APP_VERSION', '1.0.0');");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings);
    }

    public function testYieldsNothingWithoutAstSnapshot(): void
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
        $input    = new AnalysisInput($ctx);
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings);
    }
}
