<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Scanners\Checks\ArchitectureScanner;
use PHPUnit\Framework\TestCase;

final class ArchitectureScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-arch-tests-'.uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir.'/app/Http/Controllers', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir.'/app/Http/Controllers/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir.'/app/Http/Controllers');
        rmdir($this->tmpDir.'/app/Http');
        rmdir($this->tmpDir.'/app');
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function test_detects_query_in_controller_and_authorization_gap(): void
    {
        $path = $this->tmpDir.'/app/Http/Controllers/OrderController.php';
        file_put_contents($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

class OrderController
{
    public function __construct()
    {
    }

    public function index(): void
    {
        $orders = $this->repository->get();
    }
}
PHP
        );

        $scanner = new ArchitectureScanner;
        $issues = $scanner->scan([$this->tmpDir], ['extensions' => ['php'], 'performance' => ['max_file_lines' => 400]]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('query-in-controller', $rules);
        $this->assertContains('authorization-gap', $rules);
    }
}
