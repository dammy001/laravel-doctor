<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Scanners\Checks\LaravelBestPracticeScanner;
use PHPUnit\Framework\TestCase;

final class LaravelBestPracticeScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-best-practice-tests-'.uniqid();
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

    public function test_reports_controller_best_practice_gaps(): void
    {
        $path = $this->tmpDir.'/app/Http/Controllers/UserController.php';
        file_put_contents($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

class UserController
{
    public function index($request): void
    {
        DB::table('users')->get();
        DB::table('users')->count();
        Cache::remember('users', 60, fn () => DB::table('users')->get());
        Redis::get('users');
    }

    public function show(): void
    {
        $user = User::where('id', 1)->first();
    }
}
PHP
        );

        $scanner = new LaravelBestPracticeScanner;
        $issues = $scanner->scan([$this->tmpDir], ['extensions' => ['php']]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('controller-facade-overuse', $rules);
        $this->assertContains('first-without-fail', $rules);
    }
}
