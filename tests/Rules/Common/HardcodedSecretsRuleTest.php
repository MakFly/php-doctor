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

    // -------------------------------------------------------------------------
    // New positive tests (Fix 4 — ArrayItem, ClassConst, Property, Assignment)
    // -------------------------------------------------------------------------

    public function testDetectsStripeKeyInArrayItem(): void
    {
        // Build token via concatenation to avoid GitHub Push Protection
        $stripeKey = 'sk_' . 'live_' . str_repeat('x', 24);
        $input = $this->makeInput("<?php \$cfg = ['api_key' => '{$stripeKey}'];");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings, 'Stripe live key in array item should be flagged');
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
        $this->assertSame(Severity::Critical, $findings[0]->severity);
    }

    public function testDetectsSecretKeyInArrayItem(): void
    {
        $input = $this->makeInput("<?php return ['password' => 'super-real-password-value'];");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings, 'Hardcoded password in array item should be flagged');
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    public function testDetectsSecretInClassConst(): void
    {
        $input = $this->makeInput("<?php class C { const API_TOKEN = 'real-secret-value-here'; }");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings, 'Hardcoded secret in class constant should be flagged');
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
        $this->assertSame(Severity::Critical, $findings[0]->severity);
    }

    public function testDetectsSlackTokenPattern(): void
    {
        // Slack bot token pattern: xoxb- + at least 10 alphanumeric chars
        $slackToken = 'xoxb-' . str_repeat('1234567890', 3);
        $input = $this->makeInput("<?php \$t = '{$slackToken}';");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings, 'Slack bot token should be flagged');
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    public function testDetectsGoogleApiKeyPattern(): void
    {
        // Google API key: AIza + 35 chars
        $googleKey = 'AIza' . str_repeat('A1b2C3d4', 4) . 'xyz';
        $input = $this->makeInput("<?php \$k = '{$googleKey}';");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings, 'Google API key should be flagged');
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    public function testDetectsSecretInClassProperty(): void
    {
        // Property name must match the SECRET_KEY_PATTERN — use TOKEN which is unambiguous
        $input = $this->makeInput("<?php class C { private string \$apiToken = 'real-token-value-here'; }");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertNotEmpty($findings, 'Hardcoded secret in class property should be flagged');
        $this->assertSame('common.security.hardcoded-secrets', $findings[0]->ruleId);
    }

    // -------------------------------------------------------------------------
    // New negative tests (Fix 4 — placeholders / empty values)
    // -------------------------------------------------------------------------

    public function testDoesNotFlagEmptyArrayItemValue(): void
    {
        $input = $this->makeInput("<?php \$cfg = ['api_key' => ''];");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings, 'Empty string in array item should not be flagged');
    }

    public function testDoesNotFlagPlaceholderArrayItemValue(): void
    {
        $input = $this->makeInput("<?php \$cfg = ['api_key' => 'changeme'];");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings, 'Placeholder value in array item should not be flagged');
    }

    public function testDoesNotFlagPlaceholderInClassConst(): void
    {
        $input = $this->makeInput("<?php class C { const API_TOKEN = 'changeme'; }");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings, 'Placeholder value in class constant should not be flagged');
    }

    public function testDoesNotFlagNonSecretArrayKey(): void
    {
        $input = $this->makeInput("<?php \$cfg = ['name' => 'John Doe'];");
        $findings = iterator_to_array($this->rule->analyze($input));

        $this->assertEmpty($findings, 'Non-secret array key should not be flagged');
    }
}
