<?php

namespace Bunce\LaravelDoctor\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\AbstractPathScanner;

final class CorrectnessScanner extends AbstractPathScanner
{
    public function label(): string
    {
        return 'correctness';
    }

    /** @param array<string, mixed> $config @return array<int, Issue> */
    public function scan(array $paths, array $config): array
    {
        $issues = [];
        $extensions = $this->normalizeExtensions($config['extensions'] ?? ['php']);
        $files = $this->gatherPhpFiles($paths, $extensions);

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $lineNumber => $line) {
                if (preg_match('/\b(catch\s*\(.*\)\s*\{\s*\})/i', trim($line))) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::CORRECTNESS,
                        severity: IssueSeverity::MEDIUM,
                        rule: 'empty-catch',
                        message: 'Empty catch block can hide runtime failures.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Log the exception and handle or rethrow it with contextual context.',
                        code: trim($line),
                    ));
                }

                if (preg_match('/\bdd\(|\bdump\(/i', $line)) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::CORRECTNESS,
                        severity: IssueSeverity::MEDIUM,
                        rule: 'debug-artifact',
                        message: 'Debug statement remains in non-test code.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Remove before release and use logs with dedicated channels.',
                        code: trim($line),
                    ));
                }

                if (preg_match('/\bTODO|FIXME\b/i', $line)) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::CORRECTNESS,
                        severity: IssueSeverity::LOW,
                        rule: 'todo-fixme',
                        message: 'TODO/FIXME marker indicates unfinished logic.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Track this work in an issue and keep production code free of unresolved placeholders.',
                        code: trim($line),
                    ));
                }

                if (str_contains($line, 'return null;') && str_contains($line, '?->')) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::CORRECTNESS,
                        severity: IssueSeverity::LOW,
                        rule: 'nullable-return-path',
                        message: 'Nullable return path may hide model not found edge cases.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Prefer explicit not-found handling (firstOrFail/findOrFail) and clear response types.',
                        code: trim($line),
                    ));
                }
            }
        }

        return $issues;
    }
}
