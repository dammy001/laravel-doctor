<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Scanners\Checks\DatabaseScanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DatabaseScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-db-tests-'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir.'/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function test_extracts_query_operations_from_fluent_chain(): void
    {
        $scanner = new DatabaseScanner;
        $reflection = new ReflectionClass($scanner);
        $method = $reflection->getMethod('extractOperationsFromChain');
        $method->setAccessible(true);

        /** @var list<array{operation: string, column: string}> $operations */
        $operations = $method->invoke($scanner, "->where('email', '=', 'x')->orderBy('created_at')->groupBy('tenant_id')");

        $normalized = array_column($operations, 'column');
        $this->assertContains('email', $normalized);
        $this->assertContains('created_at', $normalized);
        $this->assertContains('tenant_id', $normalized);
    }

    public function test_reports_model_to_table_guess_and_identifiers(): void
    {
        $scanner = new DatabaseScanner;
        $reflection = new ReflectionClass($scanner);

        $modelToTable = $reflection->getMethod('modelToTable');
        $modelToTable->setAccessible(true);

        $normalizeIdentifier = $reflection->getMethod('normalizeIdentifier');
        $normalizeIdentifier->setAccessible(true);

        $resolveTableName = $reflection->getMethod('resolveTableName');
        $resolveTableName->setAccessible(true);

        $isIndexed = $reflection->getMethod('isIndexed');
        $isIndexed->setAccessible(true);

        $this->assertSame('audit_logs', $modelToTable->invoke($scanner, 'App\\Models\\AuditLog'));
        $this->assertSame('name', $normalizeIdentifier->invoke($scanner, ' `name` '));
        $this->assertSame('orders', $resolveTableName->invoke($scanner, 'orders AS o'));
        $this->assertTrue($isIndexed->invoke($scanner, [['id'], ['email']], 'email', 'where'));
        $this->assertFalse($isIndexed->invoke($scanner, [['email']], 'tenant_id', 'where'));
    }

    public function test_scanner_is_skipped_when_disabled(): void
    {
        $path = $this->tmpDir.'/QueryService.php';
        file_put_contents($path, <<<'PHP'
<?php

class QueryService
{
    public function users(): void
    {
        DB::table('users')
            ->where('email', 'x')
            ->orderBy('created_at');
    }
}
PHP
        );

        $scanner = new DatabaseScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'index_checks' => [
                'enabled' => false,
            ],
            'database_checks' => [
                'enabled' => false,
            ],
        ]);

        $this->assertSame([], $issues);
    }

    public function test_database_checks_detect_performance_anti_patterns(): void
    {
        $path = $this->tmpDir.'/QueryService.php';
        file_put_contents($path, <<<'PHP'
<?php

class QueryService
{
    public function users(): void
    {
        DB::table('users')
            ->where('email', 'like', '%@example.com')
            ->whereRaw("DATE(created_at) = '2024-01-01'")
            ->whereIn('id', [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22])
            ->select('*')
            ->orderByRaw('RAND()');
    }
}
PHP
        );

        $scanner = new DatabaseScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'index_checks' => [
                'enabled' => false,
            ],
            'database_checks' => [
                'enabled' => true,
                'max_in_list_items' => 20,
            ],
        ]);

        $rules = array_map(static fn (Issue $issue) => $issue->rule, $issues);
        $this->assertContains('leading-wildcard-like', $rules);
        $this->assertContains('large-in-list', $rules);
        $this->assertContains('select-star', $rules);
        $this->assertContains('non-sargable-function-filter', $rules);
        $this->assertContains('random-ordering', $rules);
    }

    public function test_large_in_list_respects_threshold(): void
    {
        $path = $this->tmpDir.'/QueryService.php';
        file_put_contents($path, <<<'PHP'
<?php

class QueryService
{
    public function users(): void
    {
        DB::table('users')->whereIn('id', [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20]);
    }
}
PHP
        );

        $scanner = new DatabaseScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'index_checks' => [
                'enabled' => false,
            ],
            'database_checks' => [
                'enabled' => true,
                'max_in_list_items' => 20,
            ],
        ]);

        $rules = array_map(static fn (Issue $issue) => $issue->rule, $issues);
        $this->assertNotContains('large-in-list', $rules);
    }
}
