<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Scanners\Checks\PerformanceScanner;
use PHPUnit\Framework\TestCase;

final class PerformanceScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-tests-'.uniqid();
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

    public function test_unbounded_results_rule_is_reported_when_threshold_exceeded(): void
    {
        $path = $this->tmpDir.'/SampleRepository.php';
        file_put_contents($path, <<<'PHP'
<?php

class SampleRepository
{
    public function scan(): void
    {
        $users = User::all();
        $users = User::all();
        $users = User::all();
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 1],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);

        $this->assertContains('unbounded-results', $rules);
    }

    public function test_n_plus_one_pattern_is_detected(): void
    {
        $path = $this->tmpDir.'/OrderController.php';
        file_put_contents($path, <<<'PHP'
<?php

class OrderController
{
    public function index(array $orders): void
    {
        foreach ($orders as $order) {
            $order->comments()->get();
            $order->user->name;
        }
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 6],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('n-plus-one-query', $rules);
    }

    public function test_raw_query_operations_are_flagged(): void
    {
        $path = $this->tmpDir.'/QueryController.php';
        file_put_contents($path, <<<'PHP'
<?php

class QueryController
{
    public function index(): void
    {
        DB::table('orders')->whereRaw('status = ?', ['pending'])->orderByRaw('created_at DESC')->get();
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 20],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('raw-query-operations', $rules);
    }

    public function test_memory_growth_in_loop_is_flagged(): void
    {
        $path = $this->tmpDir.'/MemoryController.php';
        file_put_contents($path, <<<'PHP'
<?php

class MemoryController
{
    public function load(): void
    {
        $buffer = [];
        foreach (range(1, 10) as $i) {
            $buffer[] = $i;
            $buffer[] = $i * 2;
            $buffer[] = $i * 3;
        }
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 20, 'memory_growth_threshold_per_loop' => 2],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('memory-growth-in-loop', $rules);
    }

    public function test_memory_growth_below_threshold_is_not_flagged(): void
    {
        $path = $this->tmpDir.'/SmallMemoryController.php';
        file_put_contents($path, <<<'PHP'
<?php

class SmallMemoryController
{
    public function load(): void
    {
        $buffer = [];
        foreach (range(1, 2) as $i) {
            $buffer[] = $i;
        }
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 20, 'memory_growth_threshold_per_loop' => 5],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertNotContains('memory-growth-in-loop', $rules);
    }

    public function test_memory_query_materialization_is_flagged(): void
    {
        $path = $this->tmpDir.'/MaterializationController.php';
        file_put_contents($path, <<<'PHP'
<?php

class MaterializationController
{
    public function export(): void
    {
        $users = DB::table('users')->get();
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 1],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('memory-query-materialization', $rules);
    }

    public function test_to_array_materialization_is_flagged(): void
    {
        $path = $this->tmpDir.'/ToArrayController.php';
        file_put_contents($path, <<<'PHP'
<?php

class ToArrayController
{
    public function export(): void
    {
        $payload = DB::table('users')->get()->toArray();
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 1],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('memory-toarray-materialization', $rules);
    }

    public function test_cached_full_result_set_is_flagged(): void
    {
        $path = $this->tmpDir.'/CacheController.php';
        file_put_contents($path, <<<'PHP'
<?php

class CacheController
{
    public function cacheUsers(): void
    {
        Cache::remember('users', 3600, fn() => DB::table('users')->get());
    }
}
PHP
        );

        $scanner = new PerformanceScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 1],
            'index_checks' => [
                'enabled' => false,
            ],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('memory-cache-full-result', $rules);
    }
}
