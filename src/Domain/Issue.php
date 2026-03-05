<?php

namespace Bunce\LaravelDoctor\Domain;

final class Issue
{
    public function __construct(
        public readonly IssueCategory $category,
        public readonly IssueSeverity $severity,
        public readonly string $rule,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly string $recommendation,
        public readonly ?string $code = null,
    ) {}

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'rule' => $this->rule,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'recommendation' => $this->recommendation,
            'code' => $this->code,
        ];
    }

    public static function fromArray(array $payload): self
    {
        $severity = is_string($payload['severity'] ?? null) ? strtolower((string) $payload['severity']) : 'low';
        $category = is_string($payload['category'] ?? null) ? strtolower((string) $payload['category']) : 'correctness';

        return new self(
            category: IssueCategory::tryFrom($category) ?? IssueCategory::CORRECTNESS,
            severity: IssueSeverity::tryFrom($severity) ?? IssueSeverity::LOW,
            rule: (string) ($payload['rule'] ?? 'unknown-rule'),
            message: (string) ($payload['message'] ?? 'Unknown issue'),
            file: (string) ($payload['file'] ?? ''),
            line: is_numeric($payload['line'] ?? null) ? (int) $payload['line'] : 1,
            recommendation: (string) ($payload['recommendation'] ?? ''),
            code: isset($payload['code']) && is_string($payload['code']) ? $payload['code'] : null,
        );
    }

    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->category->value,
            $this->severity->value,
            $this->rule,
            $this->file,
            (string) $this->line,
            $this->message,
        ]));
    }
}
