<?php

namespace NickPotts\Slice\Providers\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Scans directories for Eloquent model files.
 *
 * Responsible only for finding PHP files that contain Eloquent models.
 */
class ModelScanner
{
    /**
     * Scan a directory for Eloquent model classes.
     *
     * @param  string  $directory  Absolute path to directory
     * @param  string  $baseNamespace  Base namespace for discovered classes (e.g., 'App\\Models')
     * @return array<string> Array of fully qualified class names
     */
    public function scan(string $directory, string $baseNamespace): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $models = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->fileToClassName($file->getPathname(), $directory, $baseNamespace);

            // Try to load the class via autoloader (composer PSR-4)
            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
                if ($reflection->isSubclassOf(Model::class) && !$reflection->isAbstract()) {
                    $models[] = $className;
                }
            } catch (\ReflectionException) {
                // Skip if reflection fails
                continue;
            }
        }

        return $models;
    }

    /**
     * Convert a file path to a fully qualified class name.
     */
    private function fileToClassName(string $filePath, string $baseDirectory, string $baseNamespace): string
    {
        $relative = str_replace($baseDirectory, '', $filePath);
        $relative = str_replace('.php', '', $relative);
        $relative = trim($relative, '/\\');
        $relative = str_replace('/', '\\', $relative);

        return rtrim($baseNamespace, '\\') . '\\' . $relative;
    }
}