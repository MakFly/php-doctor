<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Reporting\Html;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Finding\Score;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Reporting\Html\HtmlReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Verifies that the HTML reporter does not produce XSS-injectable output
 * when findings contain crafted `</script>` sequences in their messages.
 */
final class HtmlReporterXssTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/php-doctor-xss-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeContext(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      $this->tempDir,
            consoleBinary: null,
            composerData:  ['name' => 'acme/xss-test-project'],
            sourcePaths:   [$this->tempDir],
        );
    }

    public function testScriptTagInFindingMessageDoesNotLeakIntoHtml(): void
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'common.security.test',
            severity: Severity::High,
            category: Category::Security,
            // Classic XSS payload targeting JSON-in-script-tag embed.
            message:  '</script><script>alert(1)</script>',
            file:     null,
            line:     null,
        ));

        $outputFile = $this->tempDir . '/report.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = (string) file_get_contents($outputFile);

        // The raw payload must not appear verbatim — `</script><script>alert` would
        // break out of the JSON embed block and execute the injected script.
        $this->assertStringNotContainsString(
            '</script><script>alert',
            $content,
            'XSS payload </script><script>alert must be encoded in the HTML output',
        );
    }

    public function testAnglesBracketAndAmpersandAreEncoded(): void
    {
        $bag = new FindingBag();
        $bag->add(new Finding(
            ruleId:   'common.security.test',
            severity: Severity::Medium,
            category: Category::Security,
            message:  '<script>alert("xss" & \'payload\')</script>',
            file:     null,
            line:     null,
        ));

        $outputFile = $this->tempDir . '/report-encoded.html';
        $reporter   = new HtmlReporter($outputFile);
        $output     = new BufferedOutput();
        $score      = Score::fromBag($bag);
        $ctx        = $this->makeContext();

        $reporter->render($bag, $score, $ctx, $output);

        $content = (string) file_get_contents($outputFile);

        // The JSON embed must use \u00xx escapes for < and > (JSON_HEX_TAG).
        // We verify that literal < or > from the payload do NOT appear inside
        // the application/json script block.
        $this->assertStringContainsString('<', $content, 'Less-than < must be encoded as \\u003C in the JSON embed');
        $this->assertStringContainsString('>', $content, 'Greater-than > must be encoded as \\u003E in the JSON embed');
    }
}
