<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Scanners\Checks\PerformanceScanner;
use PHPUnit\Framework\TestCase;

final class PerformanceScannerAstTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-performance-ast-tests-'.uniqid();
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

    public function test_ast_rule_is_reported_for_query_in_loop(): void
    {
        $path = $this->tmpDir.'/OrderController.php';
        file_put_contents($path, <<<'PHP'
<?php

class OrderController
{
    public function index(array $orders): void
    {
        foreach ($orders as $order) {
            User::where('id', $order->user_id)->first();
        }
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => [
                'unbounded_get_max_per_file' => 6,
            ],
            'index_checks' => [
                'enabled' => false,
            ],
            'database_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('n-plus-one-query-ast', $rules);
    }
}
