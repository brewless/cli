<?php

declare(strict_types=1);

namespace App\Support;

use Composer\Semver\VersionParser;
use Throwable;

/**
 * Which PHP version a new project is built for: the newest one Brewless
 * builds that the project itself allows. What a project allows is read from
 * the `php` requirement of its composer.json and of every package in its
 * composer.lock that a build installs (not the development ones), because
 * `composer install` in the build refuses a PHP one of them does not take.
 */
final class PhpChoice
{
    /**
     * @param  string  $newest  the version Brewless names for new projects, such as "8.6"
     * @param  list<string>  $versions  every version Brewless builds
     * @return array{php: string|null, held_back_by: string|null, constraint: string|null} the choice, and when it is not the newest, what stood in the way and what that asks for; no choice when nothing Brewless builds is allowed
     */
    public static function for(string $directory, string $newest, array $versions): array
    {
        $constraints = self::constraints($directory);

        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        // The newest first, then the others from new to old.
        $candidates = array_values(array_unique([$newest, ...array_filter($versions, static fn (string $version): bool => version_compare($version, $newest, '<'))]));
        $heldBackBy = null;
        $constraint = null;

        foreach ($candidates as $candidate) {
            $refusing = self::refusing($constraints, $candidate);

            if ($refusing === null) {
                return ['php' => $candidate, 'held_back_by' => $heldBackBy, 'constraint' => $constraint];
            }

            // What stood in the way of the newest is what a person wants to hear about.
            $heldBackBy ??= $refusing;
            $constraint ??= $constraints[$refusing];
        }

        return ['php' => null, 'held_back_by' => $heldBackBy, 'constraint' => $constraint];
    }

    /**
     * The `php` requirements of the project: its own under "composer.json", the others under the name of their package.
     *
     * @return array<string, string>
     */
    private static function constraints(string $directory): array
    {
        $constraints = [];
        $composer = json_decode((string) @file_get_contents($directory.'/composer.json'), true);

        if (is_array($composer) && is_array($composer['require'] ?? null) && is_string($composer['require']['php'] ?? null)) {
            $constraints['composer.json'] = $composer['require']['php'];
        }

        $lock = json_decode((string) @file_get_contents($directory.'/composer.lock'), true);

        foreach (is_array($lock) && is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_array($package['require'] ?? null) && is_string($package['require']['php'] ?? null)) {
                $constraints[$package['name']] = $package['require']['php'];
            }
        }

        return $constraints;
    }

    /**
     * The first requirement no release of this PHP version meets; null when all of them take it.
     *
     * @param  array<string, string>  $constraints
     */
    private static function refusing(array $constraints, string $version): ?string
    {
        $parser = new VersionParser;

        foreach ($constraints as $name => $constraint) {
            try {
                // Any release of the version will do: 8.6 is 8.6.0 today and 8.6.9 later.
                if (! $parser->parseConstraints($constraint)->matches($parser->parseConstraints('~'.$version.'.0'))) {
                    return $name;
                }
            } catch (Throwable) {
                // A requirement that cannot be read is Composer's to judge, in the build.
                continue;
            }
        }

        return null;
    }
}
