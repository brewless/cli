<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The whole of a project's brewless.yml, as Brewless reads it for a plan or a
 * deploy. Brewless validates it; this only parses it and finds the line of a
 * key, so an error can point at the line it is about.
 */
final class Manifest
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data, private readonly string $text) {}

    public static function read(string $directory): self
    {
        $path = $directory.'/'.Project::FILE;

        if (! is_file($path)) {
            throw new RuntimeException('There is no '.Project::FILE.' here. Run: brewless init');
        }

        $text = (string) file_get_contents($path);

        try {
            $data = Yaml::parse($text);
        } catch (ParseException $exception) {
            throw new RuntimeException(Project::FILE.' cannot be read on line '.$exception->getParsedLine().': '.$exception->getMessage());
        }

        if (! is_array($data)) {
            throw new RuntimeException(Project::FILE.' is empty.');
        }

        // YAML reads an unquoted 8.4 as a number; that is taken as it is meant.
        if (is_float($data['php'] ?? null) || is_int($data['php'] ?? null)) {
            $data['php'] = (string) $data['php'];
        }

        /** @var array<string, mixed> $data */
        return new self($data, $text);
    }

    /**
     * The environment a command is about when it names none.
     */
    public function defaultEnvironment(): ?string
    {
        $default = $this->data['default-environment'] ?? null;

        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * The line a key is written on, by its dotted path, such as
     * `environments.production.web.memory`; a number is an item of a list.
     * Null when it cannot be found, such as for a key that is missing.
     */
    public function lineOf(string $path): ?int
    {
        $lines = explode("\n", $this->text);
        $from = 0;
        $indent = -1;
        $found = null;

        foreach (explode('.', $path) as $segment) {
            $found = null;
            $item = ctype_digit($segment) ? (int) $segment : null;
            $count = 0;

            for ($index = $from; $index < count($lines); $index++) {
                $line = $lines[$index];

                if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                    continue;
                }

                $depth = strlen($line) - strlen(ltrim($line));

                if ($depth <= $indent) {
                    break;
                }

                $matches = $item === null
                    ? preg_match('/^\s*(["\']?)'.preg_quote($segment, '/').'\1\s*:/', $line) === 1
                    : str_starts_with(ltrim($line), '- ') && $count++ === $item;

                if ($matches) {
                    $found = $index;
                    $indent = $depth;
                    $from = $index + 1;

                    break;
                }
            }

            if ($found === null) {
                return null;
            }
        }

        return $found === null ? null : $found + 1;
    }
}
