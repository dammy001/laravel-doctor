<?php

namespace Bunce\LaravelDoctor\Tests\Domain;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use PHPUnit\Framework\TestCase;

final class IssueTest extends TestCase
{
    public function test_from_array_hydrates_issue(): void
    {
        $issue = Issue::fromArray([
            'category' => 'performance',
            'severity' => 'high',
            'rule' => 'sample-rule',
            'message' => 'Example issue',
            'file' => '/tmp/sample.php',
            'line' => 12,
            'recommendation' => 'Do this',
            'code' => '$query->get();',
        ]);

        $this->assertSame(IssueCategory::PERFORMANCE, $issue->category);
        $this->assertSame(IssueSeverity::HIGH, $issue->severity);
        $this->assertSame('sample-rule', $issue->rule);
        $this->assertSame('/tmp/sample.php', $issue->file);
        $this->assertSame(12, $issue->line);
    }

    public function test_fingerprint_is_stable_for_identical_issues(): void
    {
        $first = new Issue(
            category: IssueCategory::SECURITY,
            severity: IssueSeverity::MEDIUM,
            rule: 'raw-sql',
            message: 'Raw SQL usage',
            file: '/tmp/a.php',
            line: 8,
            recommendation: 'Use bindings',
            code: 'DB::raw(...)',
        );
        $second = new Issue(
            category: IssueCategory::SECURITY,
            severity: IssueSeverity::MEDIUM,
            rule: 'raw-sql',
            message: 'Raw SQL usage',
            file: '/tmp/a.php',
            line: 8,
            recommendation: 'Use bindings',
            code: null,
        );

        $this->assertSame($first->fingerprint(), $second->fingerprint());
    }
}
