<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use RuntimeException;

trait WritesExports
{
    /**
     * Write the files of an export into a folder and return where they went.
     * A file name is taken from the server, so it is kept to a plain name:
     * nothing is ever written outside the folder.
     *
     * @param  array<string, mixed>  $export
     */
    private function writeExport(array $export, mixed $output): string
    {
        $summary = is_array($export['summary'] ?? null) ? $export['summary'] : [];
        $folder = is_string($output) && $output !== ''
            ? rtrim($output, '/')
            : 'brewless-export-'.($summary['application'] ?? 'application').'-'.($summary['environment'] ?? 'environment');

        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Could not make the folder '.$folder.'.');
        }

        foreach (is_array($export['files'] ?? null) ? $export['files'] : [] as $name => $content) {
            if (! is_string($name) || ! is_string($content) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
                continue;
            }

            file_put_contents($folder.'/'.$name, $content);
        }

        return $folder;
    }
}
