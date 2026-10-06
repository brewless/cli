<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tells which framework a project uses from its Composer metadata. A guess a
 * person can overrule: `brewless init --framework=`.
 */
final class Framework
{
    public const array KNOWN = ['laravel', 'symfony', 'generic'];

    public static function detect(string $directory): string
    {
        $composer = json_decode((string) @file_get_contents($directory.'/composer.json'), true);
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return match (true) {
            isset($require['laravel/framework']) => 'laravel',
            isset($require['symfony/framework-bundle']) => 'symfony',
            default => 'generic',
        };
    }
}
