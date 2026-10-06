<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The brewless.yml of a project: which organisation and application it is.
 * It holds no secrets and belongs in the repository.
 */
final class Project
{
    public const string FILE = 'brewless.yml';

    public function __construct(
        public readonly string $organisation,
        public readonly string $application,
        public readonly string $framework,
    ) {}

    public static function exists(string $directory): bool
    {
        return is_file($directory.'/'.self::FILE);
    }

    public static function read(string $directory): self
    {
        if (! self::exists($directory)) {
            throw new RuntimeException('There is no '.self::FILE.' here. Run: brewless init');
        }

        $data = Yaml::parseFile($directory.'/'.self::FILE);

        foreach (['organisation', 'application'] as $key) {
            if (! is_array($data) || ! is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new RuntimeException(self::FILE.' has no "'.$key.'". Run brewless init again, or add it by hand.');
            }
        }

        return new self($data['organisation'], $data['application'], is_string($data['framework'] ?? null) ? $data['framework'] : 'generic');
    }

    public function write(string $directory): void
    {
        $yaml = "# Which application this is at Brewless. No secrets belong in this file.\n"
            .Yaml::dump(['organisation' => $this->organisation, 'application' => $this->application, 'framework' => $this->framework]);

        file_put_contents($directory.'/'.self::FILE, $yaml);
    }
}
