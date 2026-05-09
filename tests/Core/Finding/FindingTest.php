<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Core\Finding;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    private function makeFinding(): Finding
    {
        return new Finding(
            ruleId:   'test.rule',
            severity: Severity::High,
            category: Category::Security,
            message:  'Something is wrong',
            file:     '/app/Foo.php',
            line:     42,
            fixHint:  'Fix it',
            docUrl:   'https://example.com/docs',
        );
    }

    public function testReadonlyEnforcement(): void
    {
        $finding = $this->makeFinding();

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line — intentional mutation test
        $finding->ruleId = 'mutated';
    }

    public function testToArrayShape(): void
    {
        $finding = $this->makeFinding();
        $array   = $finding->toArray();

        $this->assertArrayHasKey('ruleId',   $array);
        $this->assertArrayHasKey('severity', $array);
        $this->assertArrayHasKey('category', $array);
        $this->assertArrayHasKey('message',  $array);
        $this->assertArrayHasKey('file',     $array);
        $this->assertArrayHasKey('line',     $array);
        $this->assertArrayHasKey('fixHint',  $array);
        $this->assertArrayHasKey('docUrl',   $array);

        $this->assertSame('test.rule',             $array['ruleId']);
        $this->assertSame(Severity::High->value,   $array['severity']);
        $this->assertSame(Category::Security->value, $array['category']);
        $this->assertSame('Something is wrong',    $array['message']);
        $this->assertSame('/app/Foo.php',          $array['file']);
        $this->assertSame(42,                      $array['line']);
        $this->assertSame('Fix it',                $array['fixHint']);
        $this->assertSame('https://example.com/docs', $array['docUrl']);
    }
}
