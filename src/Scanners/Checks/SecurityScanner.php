<?php

namespace Bunce\LaravelDoctor\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\AbstractPathScanner;

final class SecurityScanner extends AbstractPathScanner
{
    public function label(): string
    {
        return 'security';
    }

    /** @param array<string, mixed> $config @return array<int, Issue> */
    public function scan(array $paths, array $config): array
    {
        $issues = [];
        $extensions = $this->normalizeExtensions($config['extensions'] ?? ['php']);
        $files = $this->gatherPhpFiles($paths, $extensions);

        $hardcodedSecret = '/([A-Za-z_]*API|SECRET|TOKEN|KEY|PASSWORD|PWD|CLIENT_SECRET)\s*=\s*(["\']).+?\2/i';
        $dangerousFunctions = '/\b(exec|shell_exec|system|passthru|proc_open|popen|eval)\s*\(/i';

        foreach ($files as $file) {
            $lines = $this->readLines($file);

            foreach ($lines as $lineNumber => $line) {
                if (preg_match($hardcodedSecret, $line)) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::SECURITY,
                        severity: IssueSeverity::HIGH,
                        rule: 'hardcoded-credential',
                        message: 'Potential hard-coded secret/credential found in source.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Move this value to Laravel environment configuration (config/services.php via env()) and rotate the credential if leaked.',
                        code: trim($line),
                    ));
                }

                if (preg_match($dangerousFunctions, $line)) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::SECURITY,
                        severity: IssueSeverity::HIGH,
                        rule: 'dangerous-code-eval',
                        message: 'Use of process/execution function creates remote code execution and command injection risk.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Replace with safe, framework-provided alternatives or strictly validate/sanitize all inputs before calling OS execution.',
                        code: trim($line),
                    ));
                }

                if (str_contains($line, 'whereRaw(') || str_contains($line, 'selectRaw(') || str_contains($line, 'orderByRaw(')) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::SECURITY,
                        severity: IssueSeverity::MEDIUM,
                        rule: 'raw-sql',
                        message: 'Raw SQL usage can bypass query binding protections.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Use query-builder bindings or Eloquent query methods unless raw SQL is strictly required and fully parameterized.',
                        code: trim($line),
                    ));
                }
            }
        }

        return $issues;
    }
}
