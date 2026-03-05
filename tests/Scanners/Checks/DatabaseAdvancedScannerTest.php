<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Scanners\Checks\DatabaseAdvancedScanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DatabaseAdvancedScannerTest extends TestCase
{
    public function test_extracts_candidate_columns_from_query_chain(): void
    {
        $scanner = new DatabaseAdvancedScanner;
        $reflection = new ReflectionClass($scanner);
        $method = $reflection->getMethod('extractIndexedColumns');
        $method->setAccessible(true);

        $columns = $method->invoke(
            $scanner,
            "User::where('tenant_id', 1)->where('status', 'active')->orderBy('created_at')->get()"
        );

        $this->assertSame(['tenant_id', 'status', 'created_at'], $columns);
    }

    public function test_detects_existing_composite_index_prefix(): void
    {
        $scanner = new DatabaseAdvancedScanner;
        $reflection = new ReflectionClass($scanner);
        $method = $reflection->getMethod('hasCompositeIndexPrefix');
        $method->setAccessible(true);

        $hasIndex = $method->invoke(
            $scanner,
            [['tenant_id', 'status'], ['created_at']],
            ['tenant_id', 'status']
        );
        $missingIndex = $method->invoke(
            $scanner,
            [['status', 'tenant_id']],
            ['tenant_id', 'status']
        );

        $this->assertTrue($hasIndex);
        $this->assertFalse($missingIndex);
    }
}
