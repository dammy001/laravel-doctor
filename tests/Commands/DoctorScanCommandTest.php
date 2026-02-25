<?php

namespace Bunce\LaravelDoctor\Tests\Commands;

use Bunce\LaravelDoctor\Commands\DoctorScanCommand;
use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class DoctorScanCommandTest extends TestCase
{
    public function test_calculate_score_is_clamped_between_zero_and_one_hundred(): void
    {
        $command = new DoctorScanCommand;

        $method = (new ReflectionClass($command))->getMethod('calculateScore');
        $method->setAccessible(true);

        $issues = [
            new Issue(
                category: IssueCategory::SECURITY,
                severity: IssueSeverity::HIGH,
                rule: 'dangerous-code-eval',
                message: 'x',
                file: '/tmp/sample.php',
                line: 1,
                recommendation: 'y',
            ),
            new Issue(
                category: IssueCategory::CORRECTNESS,
                severity: IssueSeverity::MEDIUM,
                rule: 'todo-fixme',
                message: 'x',
                file: '/tmp/sample.php',
                line: 2,
                recommendation: 'y',
            ),
            new Issue(
                category: IssueCategory::ARCHITECTURE,
                severity: IssueSeverity::LOW,
                rule: 'todo-fixme',
                message: 'x',
                file: '/tmp/sample.php',
                line: 3,
                recommendation: 'y',
            ),
        ];

        $score = $method->invoke($command, $issues, ['weights' => ['high' => 20, 'medium' => 10, 'low' => 4], 'base_score' => 100]);
        $this->assertSame(66, $score);

        $lowScore = $method->invoke($command, array_fill(0, 10, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::HIGH,
            rule: 'x',
            message: 'y',
            file: '/tmp/sample.php',
            line: 1,
            recommendation: 'z',
        )), ['weights' => ['high' => 20], 'base_score' => 100]);

        $this->assertSame(0, $lowScore);
    }

    public function test_build_checks_with_invalid_category_returns_empty_array(): void
    {
        $command = new DoctorScanCommand;
        $command->setOutput(new OutputStyle(new StringInput(''), new BufferedOutput));

        $method = (new ReflectionClass($command))->getMethod('buildChecks');
        $method->setAccessible(true);

        $checks = $method->invoke($command, 'not-a-category');
        $this->assertSame([], $checks);
    }

    public function test_passes_severity_filtering(): void
    {
        $command = new DoctorScanCommand;
        $command->setOutput(new OutputStyle(new StringInput(''), new BufferedOutput));

        $method = (new ReflectionClass($command))->getMethod('passesSeverity');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(
            $command,
            IssueSeverity::LOW,
            IssueSeverity::MEDIUM
        ));

        $this->assertTrue($method->invoke(
            $command,
            IssueSeverity::MEDIUM,
            IssueSeverity::LOW
        ));
    }
}
