<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime;

use PhpDoctor\Analysis\Runtime\RuntimeSnapshot;
use PHPUnit\Framework\TestCase;

final class RuntimeSnapshotTest extends TestCase
{
    public function testEmptySnapshotHasAllNullProperties(): void
    {
        $snapshot = new RuntimeSnapshot();

        $this->assertNull($snapshot->routes);
        $this->assertNull($snapshot->services);
        $this->assertNull($snapshot->listeners);
        $this->assertNull($snapshot->bundles);
        $this->assertNull($snapshot->about);
        $this->assertNull($snapshot->config);
        $this->assertNull($snapshot->migrations);
        $this->assertNull($snapshot->composerAudit);
    }

    public function testPartialSnapshotRetainsNulls(): void
    {
        $snapshot = new RuntimeSnapshot(
            routes: [['path' => '/']],
            services: ['SomeService' => []],
        );

        $this->assertIsArray($snapshot->routes);
        $this->assertIsArray($snapshot->services);
        $this->assertNull($snapshot->listeners);
        $this->assertNull($snapshot->bundles);
        $this->assertNull($snapshot->about);
        $this->assertNull($snapshot->config);
        $this->assertNull($snapshot->migrations);
        $this->assertNull($snapshot->composerAudit);
    }
}
