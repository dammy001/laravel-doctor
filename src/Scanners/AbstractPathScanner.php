<?php

namespace Bunce\LaravelDoctor\Scanners;

use Bunce\LaravelDoctor\Domain\Issue;
use Symfony\Component\Finder\Finder;

abstract class AbstractPathScanner implements DiagnosticCheck
{
    /** @param  array<int, string>  $paths
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    protected function gatherPhpFiles(array $paths, array $extensions): array
    {
        $paths = array_values(array_filter($paths, 'is_string'));
        $extensions = array_values(array_filter($extensions, 'is_string'));

        $finder = new Finder;
        $finder->files()->in($paths)->ignoreVcs(true)->ignoreDotFiles(true);

        if (! empty($extensions)) {
            $extensions = array_map(
                static fn (string $extension): string => preg_quote($extension, '/'),
                $extensions
            );
            $finder->name('/\.(?:'.implode('|', $extensions).')$/');
        }

        $files = [];

        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeExtensions(mixed $extensions): array
    {
        if (! is_array($extensions)) {
            return ['php'];
        }

        $normalized = array_values(array_filter($extensions, 'is_string'));

        return $normalized === [] ? ['php'] : $normalized;
    }

    /**
     * @param  array<int, Issue>  $issues
     */
    protected function addIssue(array &$issues, Issue $issue): void
    {
        $issues[] = $issue;
    }

    abstract public function label(): string;

    /**
     * @param  array<int, string>  $paths
     * @return array<int, Issue>
     */
    abstract public function scan(array $paths, array $config): array;
}
