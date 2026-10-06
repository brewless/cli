<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * The file an environment's variables are edited in on this machine:
 * .env.<environment>. Its first line notes which revision it was pulled at,
 * so a push can tell when someone else pushed in between.
 */
final class EnvFile
{
    private const string HEADER = '/^# brewless: revision (\d+)[^\n]*\n/';

    public static function path(string $directory, string $environment, ?string $file): string
    {
        return $file !== null && $file !== '' ? $file : $directory.'/.env.'.$environment;
    }

    /**
     * The revision the file says it was pulled at, or null when it does not say.
     */
    public static function revision(#[SensitiveParameter] string $content): ?int
    {
        return preg_match(self::HEADER, $content, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * The content without the line this client added.
     */
    public static function withoutHeader(#[SensitiveParameter] string $content): string
    {
        return (string) preg_replace(self::HEADER, '', $content, 1);
    }

    public static function withHeader(#[SensitiveParameter] string $content, int $revision, string $secret): string
    {
        return '# brewless: revision '.$revision.' of '.$secret.". Keep this line; it tells a push what you started from.\n".self::withoutHeader($content);
    }

    /**
     * The names of the variables in a dotenv file.
     *
     * @return list<string>
     */
    public static function names(#[SensitiveParameter] string $content): array
    {
        try {
            $names = array_map(strval(...), array_keys(Dotenv::parse($content)));
        } catch (ExceptionInterface) {
            throw new RuntimeException('The file is not a valid dotenv file. Nothing was pushed.');
        }

        sort($names);

        return $names;
    }
}
