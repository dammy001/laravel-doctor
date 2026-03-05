<?php

namespace Bunce\LaravelDoctor\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\AbstractPathScanner;

final class LaravelBestPracticeScanner extends AbstractPathScanner
{
    public function label(): string
    {
        return 'architecture';
    }

    /** @param array<string, mixed> $config @return array<int, Issue> */
    public function scan(array $paths, array $config): array
    {
        $issues = [];
        $extensions = $this->normalizeExtensions($config['extensions'] ?? ['php']);
        $files = $this->gatherPhpFiles($paths, $extensions);

        foreach ($files as $file) {
            if (! str_contains($file, '/Http/Controllers/')) {
                continue;
            }

            $lines = $this->readLines($file);
            if ($lines === []) {
                continue;
            }

            $contents = implode("\n", $lines);
            $this->checkValidationInController($contents, $file, $issues);
            $this->checkFirstWithoutFail($lines, $file, $issues);
            $this->checkFacadeOveruseInController($lines, $file, $issues);
            $this->checkPaginationInIndexActions($lines, $file, $issues);
        }

        return $issues;
    }

    /** @param array<int, Issue> $issues */
    private function checkValidationInController(string $contents, string $file, array &$issues): void
    {
        if (! preg_match('/public\s+function\s+\w+\s*\([^)]+Request\s+\$\w+/i', $contents)) {
            return;
        }

        if (str_contains($contents, '->validate(') || preg_match('/extends\s+FormRequest/', $contents)) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::ARCHITECTURE,
            severity: IssueSeverity::MEDIUM,
            rule: 'controller-validation-gap',
            message: 'Controller action accepts request input without clear validation strategy.',
            file: $file,
            line: 1,
            recommendation: 'Prefer FormRequest classes or explicit $request->validate() for action inputs.',
            code: null,
        ));
    }

    /** @param array<int, string> $lines
     * @param array<int, Issue> $issues
     */
    private function checkFirstWithoutFail(array $lines, string $file, array &$issues): void
    {
        foreach ($lines as $lineNumber => $line) {
            if (! str_contains($line, '->first(')) {
                continue;
            }
            if (str_contains($line, '->firstOrFail(')) {
                continue;
            }

            $this->addIssue($issues, new Issue(
                category: IssueCategory::CORRECTNESS,
                severity: IssueSeverity::LOW,
                rule: 'first-without-fail',
                message: 'Using first() in controller logic can hide not-found paths.',
                file: $file,
                line: $lineNumber + 1,
                recommendation: 'Use firstOrFail()/findOrFail() where a missing resource should return 404.',
                code: trim($line),
            ));
        }
    }

    /** @param array<int, string> $lines
     * @param array<int, Issue> $issues
     */
    private function checkFacadeOveruseInController(array $lines, string $file, array &$issues): void
    {
        $count = 0;
        foreach ($lines as $lineNumber => $line) {
            if (! preg_match('/\b(DB|Cache|Http|Redis)::/i', $line)) {
                continue;
            }
            $count++;
            if ($count < 4) {
                continue;
            }

            $this->addIssue($issues, new Issue(
                category: IssueCategory::ARCHITECTURE,
                severity: IssueSeverity::LOW,
                rule: 'controller-facade-overuse',
                message: 'Controller contains multiple infrastructure facade calls.',
                file: $file,
                line: $lineNumber + 1,
                recommendation: 'Move persistence/integration logic into services/actions and keep controllers orchestration-focused.',
                code: trim($line),
            ));

            return;
        }
    }

    /** @param array<int, string> $lines
     * @param array<int, Issue> $issues
     */
    private function checkPaginationInIndexActions(array $lines, string $file, array &$issues): void
    {
        foreach ($lines as $lineNumber => $line) {
            if (! preg_match('/public\s+function\s+index\s*\(/i', $line)) {
                continue;
            }

            $window = implode("\n", array_slice($lines, $lineNumber, 50));
            if (str_contains($window, '->paginate(') || str_contains($window, '->cursorPaginate(')) {
                continue;
            }
            if (! preg_match('/::all\(|->get\(/', $window)) {
                continue;
            }

            $this->addIssue($issues, new Issue(
                category: IssueCategory::PERFORMANCE,
                severity: IssueSeverity::MEDIUM,
                rule: 'index-without-pagination',
                message: 'Index action appears to materialize full result sets.',
                file: $file,
                line: $lineNumber + 1,
                recommendation: 'Use paginate()/cursorPaginate() for list endpoints.',
                code: trim($line),
            ));
        }
    }
}
