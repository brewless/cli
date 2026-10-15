<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\ApiException;
use App\Support\Manifest;
use App\Support\Project;

/**
 * What Brewless refused in brewless.yml, each message with the line of the
 * file it is about. Shared by `plan` and `deploy`.
 */
trait ReportsManifestErrors
{
    /**
     * Whether the server refused the file; then the reasons are printed.
     */
    private function manifestRefused(Manifest $manifest, ApiException $exception): bool
    {
        $errors = array_filter($exception->errors, static fn (string $field): bool => $field === 'manifest' || str_starts_with($field, 'manifest.'), ARRAY_FILTER_USE_KEY);

        if ($exception->status !== 422 || $errors === []) {
            return false;
        }

        $this->components->error(Project::FILE.' cannot be used as it is.');

        foreach ($errors as $field => $messages) {
            $path = preg_replace('/^manifest\.?/', '', $field) ?? $field;
            $line = $path === '' ? null : $manifest->lineOf($path);

            foreach ($messages as $message) {
                $this->line('  '.($line === null ? '' : '<fg=gray>line '.$line.':</> ').$message);
            }
        }

        return true;
    }
}
