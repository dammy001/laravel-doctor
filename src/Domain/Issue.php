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
}
