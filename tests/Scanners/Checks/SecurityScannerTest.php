<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Checks;

use Bunce\LaravelDoctor\Scanners\Checks\SecurityScanner;
use PHPUnit\Framework\TestCase;

final class SecurityScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-security-tests-'.uniqid();
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

    public function test_detects_hardcoded_credentials_and_dangerous_functions(): void
    {
        $path = $this->tmpDir.'/SecurityService.php';
        file_put_contents($path, <<<'PHP'
<?php

class SecurityService
{
    public function run($payload): void
    {
        $API_TOKEN = 'topsecret';
        eval($payload);
    }
}
PHP
        );

        $scanner = new SecurityScanner;
        $issues = $scanner->scan([$this->tmpDir], [
            'extensions' => ['php'],
            'performance' => ['unbounded_get_max_per_file' => 6],
            'index_checks' => ['enabled' => false],
        ]);

        $rules = array_map(static fn ($issue) => $issue->toArray()['rule'], $issues);
        $this->assertContains('hardcoded-credential', $rules);
        $this->assertContains('dangerous-code-eval', $rules);
    }
}
