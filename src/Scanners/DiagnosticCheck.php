<?php

namespace Bunce\LaravelDoctor\Scanners;

use Bunce\LaravelDoctor\Domain\Issue;

interface DiagnosticCheck
{
    public function label(): string;

    /**
     * @param  array<int, string>  $paths
     * @param  array<string, mixed>  $config
     * @return array<int, Issue>
     */
    public function scan(array $paths, array $config): array;
}
