<?php

namespace Bunce\LaravelDoctor\Tests\Scanners\Ast;

use Bunce\LaravelDoctor\Scanners\Ast\AstEngine;
use PHPUnit\Framework\TestCase;

final class AstEngineTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/laravel-doctor-ast-tests-'.uniqid();
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

    public function test_detects_query_calls_inside_loops(): void
    {
        $path = $this->tmpDir.'/LoopQuery.php';
        file_put_contents($path, <<<'PHP'
<?php

class LoopQuery
{
    public function handle(array $users): void
    {
        foreach ($users as $user) {
            User::where('id', $user->id)->first();
        }
    }
}
PHP
        );

        $engine = new AstEngine;
        $results = $engine->findPotentialLoopQueries($path);

        $this->assertNotEmpty($results);
        $this->assertGreaterThan(0, $results[0]['line']);
    }
}
