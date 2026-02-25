<?php

namespace Bunce\LaravelDoctor\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\AbstractPathScanner;

final class ArchitectureScanner extends AbstractPathScanner
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
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $lineCount = count($lines);
            $performanceConfig = is_array($config['performance'] ?? null) ? $config['performance'] : [];
            $maxControllerLines = isset($performanceConfig['max_file_lines']) && is_numeric($performanceConfig['max_file_lines']) ? (int) $performanceConfig['max_file_lines'] : 400;

            if ($lineCount >= $maxControllerLines) {
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::ARCHITECTURE,
                    severity: IssueSeverity::MEDIUM,
                    rule: 'large-class',
                    message: 'Large class file may indicate violated boundaries.',
                    file: $file,
                    line: 1,
                    recommendation: 'Split responsibilities across focused services/form objects/pipelines and keep classes small.',
                    code: null,
                ));
            }

            if (str_contains($file, '/Http/Controllers/') && preg_match('/\\$this->/', $contents)) {
                $controllerMethodLines = [];
                foreach ($lines as $lineNumber => $line) {
                    if (preg_match('/\$this->.+->(get|first|pluck|count|exists)\(/', $line)) {
                        $this->addIssue($issues, new Issue(
                            category: IssueCategory::ARCHITECTURE,
                            severity: IssueSeverity::MEDIUM,
                            rule: 'query-in-controller',
                            message: 'Direct query logic inside controller method.',
                            file: $file,
                            line: $lineNumber + 1,
                            recommendation: 'Move data access queries into repositories/services and keep controllers thin.',
                            code: trim($line),
                        ));
                        break;
                    }
                }
            }

            if (preg_match('/\bpublic\s+function\s+__construct\s*\((.*)\)/i', $contents) && strpos($contents, '->authorize(') === false && str_contains($file, '/Http/Controllers/')) {
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::ARCHITECTURE,
                    severity: IssueSeverity::LOW,
                    rule: 'authorization-gap',
                    message: 'Controller constructor exists without visible authorization strategy.',
                    file: $file,
                    line: 1,
                    recommendation: 'Use middleware/policies/gates consistently and document protected methods.',
                    code: null,
                ));
            }
        }

        return $issues;
    }
}
