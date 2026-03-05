<?php

namespace Bunce\LaravelDoctor\Scanners;

use Bunce\LaravelDoctor\Domain\Issue;
use Symfony\Component\Finder\Finder;

abstract class AbstractPathScanner implements DiagnosticCheck
{
    /** @var array<string, array<int, string>> */
    private static array $linesCache = [];

    /** @var array<string, string> */
    private static array $contentCache = [];

    /** @param  array<int, string>  $paths
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    protected function gatherPhpFiles(array $paths, array $extensions): array
    {
        $paths = array_values(array_filter($paths, 'is_string'));
        $extensions = array_values(array_filter($extensions, 'is_string'));

        $directories = [];
        $files = [];
        foreach ($paths as $path) {
            $absolutePath = $path;
            if (! str_starts_with($absolutePath, '/') && function_exists('base_path')) {
                $absolutePath = base_path($absolutePath);
            }

            if (is_dir($absolutePath)) {
                $directories[] = $absolutePath;
                continue;
            }

            if (is_file($absolutePath)) {
                $files[] = $absolutePath;
            }
        }

        if ($directories !== []) {
            $finder = new Finder;
            $finder->files()->in($directories)->ignoreVcs(true)->ignoreDotFiles(true);

            if (! empty($extensions)) {
                $extensions = array_map(
                    static fn (string $extension): string => preg_quote($extension, '/'),
                    $extensions
                );
                $finder->name('/\.(?:'.implode('|', $extensions).')$/');
            }

            foreach ($finder as $file) {
                $realPath = $file->getRealPath();
                if (is_string($realPath) && $realPath !== '') {
                    $files[] = $realPath;
                }
            }
        }

        if ($extensions !== []) {
            $files = array_values(array_filter($files, function (string $file) use ($extensions): bool {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (! is_string($extension) || $extension === '') {
                    return false;
                }

                return in_array($extension, $extensions, true);
            }));
        }

        return array_values(array_unique($files));
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

    /**
     * @return array<int, string>
     */
    protected function readLines(string $file): array
    {
        if (isset(self::$linesCache[$file])) {
            return self::$linesCache[$file];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::$linesCache[$file] = is_array($lines) ? $lines : [];

        return self::$linesCache[$file];
    }

    protected function readContents(string $file): string
    {
        if (isset(self::$contentCache[$file])) {
            return self::$contentCache[$file];
        }

        $contents = file_get_contents($file);
        self::$contentCache[$file] = is_string($contents) ? $contents : '';

        return self::$contentCache[$file];
    }

    abstract public function label(): string;

    /**
     * @param  array<int, string>  $paths
     * @return array<int, Issue>
     */
    abstract public function scan(array $paths, array $config): array;
}
