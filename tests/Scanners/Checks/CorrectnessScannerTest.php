<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Scanners\Checks\CorrectnessScanner;
use PHPUnit\Framework\TestCase;

final class CorrectnessScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-correctness-tests-'.uniqid();
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

    public function test_detects_empty_catch_and_todo_debug_artifacts(): void
    {
        $path = $this->tmpDir.'/CorrectnessService.php';
        file_put_contents($path, <<<'PHP'
<?php

class CorrectnessService
{
    public function handle(): void
    {
        try {
            $x = 1 / 0;
        } catch (\Exception $e) {}

        dd($x);
        // TODO: replace with proper response handling
    }
}
PHP
        );

        $scanner = new CorrectnessScanner;
        $issues = $scanner->scan([$this->tmpDir], ['extensions' => ['php']]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('empty-catch', $rules);
        $this->assertContains('debug-artifact', $rules);
        $this->assertContains('todo-fixme', $rules);
    }
}
