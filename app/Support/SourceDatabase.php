<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * The database `db:import` copies from, gathered from what the person gave,
 * in this order: the argument, then SOURCE_DB_* (or SOURCE_DATABASE_URL),
 * then a .env of the old application (its DB_* lines and DB_URL). The DB_*
 * variables of the shell itself are never read: they belong to the
 * application being worked on. A password is never part of the argument,
 * where it would end up in the shell's history.
 */
final readonly class SourceDatabase
{
    public const string POSTGRES = 'pgsql';

    public const string MYSQL = 'mysql';

    /** What the script takes for the encryption of a connection. */
    public const array SSL_MODES = ['disable', 'require', 'verify-full'];

    /** A host, database or user name the script puts in a connection string as it is. */
    private const string HOST = '/^[A-Za-z0-9.:-]{1,253}$/D';

    private const string NAME = '/^[A-Za-z0-9_.$-]{1,64}$/D';

    public function __construct(
        public string $engine,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[SensitiveParameter]
        public ?string $password,
        public string $sslmode,
        /** Where the password came from, in words, never the password. */
        public ?string $passwordFrom,
    ) {}

    public static function gather(?string $argument, ?string $fromEnv): self
    {
        $file = self::fromFile($fromEnv);
        $given = self::fromUrl($argument, 'the address', allowPassword: false);
        $variables = [
            ...self::fromUrl(self::variable('SOURCE_DATABASE_URL'), 'SOURCE_DATABASE_URL', allowPassword: true),
            ...array_filter([
                'engine' => self::engine(self::variable('SOURCE_DB_CONNECTION')),
                'host' => self::variable('SOURCE_DB_HOST'),
                'port' => self::variable('SOURCE_DB_PORT'),
                'database' => self::variable('SOURCE_DB_DATABASE'),
                'username' => self::variable('SOURCE_DB_USERNAME'),
                'password' => self::variable('SOURCE_DB_PASSWORD'),
                'sslmode' => self::variable('SOURCE_DB_SSLMODE'),
            ], static fn (?string $value): bool => $value !== null),
        ];
        $fromFile = [
            ...self::fromUrl(self::filled($file['DB_URL'] ?? $file['DATABASE_URL'] ?? null), (string) $fromEnv, allowPassword: true),
            ...array_filter([
                'engine' => self::engine(self::filled($file['DB_CONNECTION'] ?? null)),
                'host' => self::filled($file['DB_HOST'] ?? null),
                'port' => self::filled($file['DB_PORT'] ?? null),
                'database' => self::filled($file['DB_DATABASE'] ?? null),
                'username' => self::filled($file['DB_USERNAME'] ?? null),
                'password' => self::filled($file['DB_PASSWORD'] ?? null),
                'sslmode' => self::filled($file['DB_SSLMODE'] ?? null),
            ], static fn (?string $value): bool => $value !== null),
        ];

        $pick = static fn (string $key): ?string => $given[$key] ?? $variables[$key] ?? $fromFile[$key] ?? null;
        $engine = $pick('engine') ?? throw new RuntimeException('Say which database to copy from, as postgres://<user>@<host>/<database> or mysql://…, in SOURCE_DB_CONNECTION, or with --from-env.');
        $host = $pick('host') ?? throw new RuntimeException('Name the host of the database to copy from, in the address, in SOURCE_DB_HOST, or with --from-env.');
        $database = $pick('database') ?? throw new RuntimeException('Name the database to copy from, as the path of the address, in SOURCE_DB_DATABASE, or with --from-env.');
        $username = $pick('username') ?? throw new RuntimeException('Name the user that may read the database, in the address (<user>@<host>), in SOURCE_DB_USERNAME, or with --from-env.');
        $port = $pick('port');
        $sslmode = $pick('sslmode') ?? 'require';

        $passwordFrom = match (true) {
            isset($variables['password']) => self::variable('SOURCE_DB_PASSWORD') !== null ? 'SOURCE_DB_PASSWORD' : 'SOURCE_DATABASE_URL',
            isset($fromFile['password']) => (string) $fromEnv,
            default => null,
        };

        return new self(
            engine: $engine,
            host: strtolower($host),
            port: $port !== null && ctype_digit($port) ? (int) $port : ($engine === self::POSTGRES ? 5432 : 3306),
            database: $database,
            username: $username,
            password: $variables['password'] ?? $fromFile['password'] ?? null,
            sslmode: $sslmode,
            passwordFrom: $passwordFrom,
        );
    }

    public function location(): string
    {
        return ($this->engine === self::POSTGRES ? 'postgres' : 'mysql').'://'.$this->username.'@'.$this->host.':'.$this->port.'/'.$this->database;
    }

    public function name(): string
    {
        return $this->engine === self::POSTGRES ? 'PostgreSQL' : 'MySQL';
    }

    public function withPassword(#[SensitiveParameter] string $password, string $from): self
    {
        return new self($this->engine, $this->host, $this->port, $this->database, $this->username, $password, $this->sslmode, $from);
    }

    /** Whether the host is this machine or a private network, which only --local reaches. */
    public function isPrivate(): bool
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        foreach (['.internal', '.local', '.localhost', '.lan', '.priv'] as $suffix) {
            if (str_ends_with($this->host, $suffix)) {
                return true;
            }
        }

        return $this->host === 'localhost' || ! str_contains($this->host, '.');
    }

    /** Whether the host is this machine itself, such as the end of a tunnel. */
    public function isThisMachine(): bool
    {
        return in_array($this->host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Refuse what cannot be sent on, before it travels anywhere.
     */
    public function check(): void
    {
        if (preg_match(self::HOST, $this->host) !== 1) {
            throw new RuntimeException('The host '.$this->host.' does not look like one: give a name or an address, without a scheme, port or path.');
        }

        if (preg_match(self::NAME, $this->database) !== 1 || preg_match(self::NAME, $this->username) !== 1) {
            throw new RuntimeException('Give the database and user name as they are: up to 64 letters, digits and _ . $ -.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new RuntimeException('The port of the database is a number from 1 to 65535.');
        }

        if (! in_array($this->sslmode, self::SSL_MODES, true)) {
            throw new RuntimeException('The encryption of the connection (sslmode) is disable, require or verify-full.');
        }

        if ($this->password === null || $this->password === '' || preg_match('/[\x00\r\n]/', $this->password) === 1) {
            throw new RuntimeException('Give the password of '.$this->username.' on one line.');
        }
    }

    /**
     * What Brewless gets to start a copy in the customer's project.
     *
     * @return array{engine: string, host: string, port: int, database: string, username: string, password: string|null, sslmode: string}
     */
    public function forBrewless(): array
    {
        return [...$this->described(), 'username' => $this->username, 'password' => $this->password, 'sslmode' => $this->sslmode];
    }

    /**
     * What may be written to the audit trail: no user, no password.
     *
     * @return array{engine: string, host: string, port: int, database: string}
     */
    public function described(): array
    {
        return ['engine' => $this->engine, 'host' => $this->host, 'port' => $this->port, 'database' => $this->database];
    }

    /**
     * The parts of a database address, such as postgres://user@host:5432/shop?sslmode=require.
     *
     * @return array<string, string>
     */
    private static function fromUrl(?string $value, string $what, bool $allowPassword): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $parts = parse_url($value);
        $engine = is_array($parts) ? self::engine($parts['scheme'] ?? null) : null;

        if (! is_array($parts) || $engine === null || ! isset($parts['host'])) {
            throw new RuntimeException($what.' is no database address: give it as postgres://<user>@<host>[:<port>]/<database> or mysql://…');
        }

        if (isset($parts['pass']) && ! $allowPassword) {
            throw new RuntimeException('Leave the password out of the address: the command line ends up in your shell\'s history. Give it in SOURCE_DB_PASSWORD, or type it when asked.');
        }

        parse_str($parts['query'] ?? '', $query);

        return array_filter([
            'engine' => $engine,
            'host' => trim($parts['host'], '[]'),
            'port' => isset($parts['port']) ? (string) $parts['port'] : null,
            'database' => self::filled(ltrim($parts['path'] ?? '', '/')),
            'username' => isset($parts['user']) ? urldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? urldecode($parts['pass']) : null,
            'sslmode' => is_string($query['sslmode'] ?? null) ? $query['sslmode'] : null,
        ], static fn (?string $part): bool => $part !== null);
    }

    private static function engine(?string $name): ?string
    {
        return match (strtolower((string) $name)) {
            'pgsql', 'postgres', 'postgresql' => self::POSTGRES,
            'mysql', 'mariadb' => self::MYSQL,
            default => null,
        };
    }

    /**
     * The DB_* values of a .env file of the old application; nothing else is taken from it.
     *
     * @return array<string, string>
     */
    private static function fromFile(?string $path): array
    {
        if (self::filled($path) === null) {
            return [];
        }

        $content = is_file((string) $path) ? file_get_contents((string) $path) : false;

        if ($content === false) {
            throw new RuntimeException('There is no file '.$path.' to read the database from.');
        }

        try {
            $values = Dotenv::parse($content);
        } catch (ExceptionInterface) {
            throw new RuntimeException($path.' is no .env file that can be read.');
        }

        $taken = [];

        foreach ($values as $name => $value) {
            if (is_string($value) && (str_starts_with((string) $name, 'DB_') || $name === 'DATABASE_URL')) {
                $taken[(string) $name] = $value;
            }
        }

        return $taken;
    }

    private static function variable(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) ? self::filled($value) : null;
    }

    private static function filled(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
