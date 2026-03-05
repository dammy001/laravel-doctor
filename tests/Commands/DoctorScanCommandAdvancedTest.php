<?php

namespace Bunce\LaravelDoctor\Tests\Commands;

use Bunce\LaravelDoctor\Commands\DoctorScanCommand;
use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DoctorScanCommandAdvancedTest extends TestCase
{
    public function test_filter_by_baseline_removes_known_issue_fingerprints(): void
    {
        $command = new DoctorScanCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('filterByBaseline');
        $method->setAccessible(true);

        $issueA = new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::HIGH,
            rule: 'n-plus-one-query',
            message: 'Potential N+1',
            file: '/tmp/a.php',
            line: 10,
            recommendation: 'Use with()',
            code: '$order->comments()->get();',
        );
        $issueB = new Issue(
            category: IssueCategory::SECURITY,
            severity: IssueSeverity::MEDIUM,
            rule: 'raw-sql',
            message: 'Raw SQL',
            file: '/tmp/b.php',
            line: 7,
            recommendation: 'Use bindings',
            code: 'whereRaw(...)',
        );

        $baselinePath = sys_get_temp_dir().'/doctor-baseline-'.uniqid().'.json';
        file_put_contents($baselinePath, json_encode([
            'fingerprints' => [$issueA->fingerprint()],
        ], JSON_PRETTY_PRINT));

        $filtered = $method->invoke($command, [$issueA, $issueB], $baselinePath);
        @unlink($baselinePath);

        $this->assertCount(1, $filtered);
        $this->assertSame('raw-sql', $filtered[0]->rule);
    }
}
