<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * The bucket `storage:import` copies from, gathered from what the person
 * gave, in this order: the argument and options, then SOURCE_AWS_*, then a
 * profile of the AWS CLI, then a .env of the old application. The AWS_*
 * variables of the shell itself are never read: they often belong to
 * another AWS account than the one the files are in.
 */
final readonly class SourceBucket
{
    /** What may be in a key: nothing that ends a line of rclone's configuration. */
    private const string KEY = '/^[A-Za-z0-9+\/=_.-]{8,256}$/D';

    public function __construct(
        public string $bucket,
        public string $prefix,
        public ?string $region,
        public ?string $endpoint,
        public bool $pathStyle,
        public ?string $accessKey,
        #[SensitiveParameter]
        public ?string $secretKey,
        /** Where the key came from, in words, never the key: "SOURCE_AWS_*", "profile migration", "../old/.env". */
        public ?string $keysFrom,
    ) {}

    /**
     * @param  array{from-env?: string|null, source-profile?: string|null, source-region?: string|null, source-endpoint?: string|null, source-path-style?: bool}  $options
     */
    public static function gather(?string $argument, array $options): self
    {
        $file = self::fromFile($options['from-env'] ?? null);
        $profile = self::fromProfile($options['source-profile'] ?? null);
        [$argumentBucket, $argumentPrefix] = self::split($argument);
        [$fileBucket] = self::split($file['AWS_BUCKET'] ?? null);

        $bucket = $argumentBucket ?? self::split(self::variable('SOURCE_AWS_BUCKET'))[0] ?? $fileBucket
            ?? throw new RuntimeException('Name the bucket to copy from, as s3://<bucket>, in SOURCE_AWS_BUCKET, or with --from-env.');

        $endpoint = self::filled($options['source-endpoint'] ?? null) ?? self::variable('SOURCE_AWS_ENDPOINT') ?? self::filled($file['AWS_ENDPOINT'] ?? null);
        $pathStyle = ($options['source-path-style'] ?? false) === true
            || self::truthy(self::variable('SOURCE_AWS_USE_PATH_STYLE_ENDPOINT') ?? $file['AWS_USE_PATH_STYLE_ENDPOINT'] ?? null);

        [$accessKey, $secretKey, $keysFrom] = match (true) {
            self::variable('SOURCE_AWS_ACCESS_KEY_ID') !== null && self::variable('SOURCE_AWS_SECRET_ACCESS_KEY') !== null => [self::variable('SOURCE_AWS_ACCESS_KEY_ID'), self::variable('SOURCE_AWS_SECRET_ACCESS_KEY'), 'SOURCE_AWS_ACCESS_KEY_ID and SOURCE_AWS_SECRET_ACCESS_KEY'],
            $profile['key'] !== null && $profile['secret'] !== null => [$profile['key'], $profile['secret'], 'the AWS profile '.$options['source-profile']],
            self::filled($file['AWS_ACCESS_KEY_ID'] ?? null) !== null && self::filled($file['AWS_SECRET_ACCESS_KEY'] ?? null) !== null => [$file['AWS_ACCESS_KEY_ID'], $file['AWS_SECRET_ACCESS_KEY'], (string) $options['from-env']],
            default => [null, null, null],
        };

        return new self(
            bucket: $bucket,
            prefix: $argumentPrefix ?? '',
            region: self::filled($options['source-region'] ?? null) ?? self::variable('SOURCE_AWS_DEFAULT_REGION') ?? $profile['region'] ?? self::filled($file['AWS_DEFAULT_REGION'] ?? null),
            endpoint: $endpoint === null ? null : rtrim($endpoint, '/'),
            pathStyle: $pathStyle,
            accessKey: $accessKey,
            secretKey: $secretKey,
            keysFrom: $keysFrom,
        );
    }

    public function isAws(): bool
    {
        return $this->endpoint === null;
    }

    public function hasKeys(): bool
    {
        return $this->accessKey !== null && $this->secretKey !== null;
    }

    public function location(): string
    {
        return 's3://'.$this->bucket.($this->prefix === '' ? '' : '/'.$this->prefix);
    }

    public function withKeys(string $accessKey, #[SensitiveParameter] string $secretKey, string $from): self
    {
        return new self($this->bucket, $this->prefix, $this->region, $this->endpoint, $this->pathStyle, trim($accessKey), trim($secretKey), $from);
    }

    public function withRegion(string $region): self
    {
        return new self($this->bucket, $this->prefix, $region, $this->endpoint, $this->pathStyle, $this->accessKey, $this->secretKey, $this->keysFrom);
    }

    /**
     * Refuse a key that cannot be one, before it travels anywhere.
     */
    public function checkKeys(): void
    {
        if (preg_match(self::KEY, (string) $this->accessKey) !== 1 || preg_match(self::KEY, (string) $this->secretKey) !== 1) {
            throw new RuntimeException('The key '.($this->keysFrom === null ? '' : 'from '.$this->keysFrom.' ').'does not look like an S3 key: 8 or more letters, digits and + / = _ . -, without spaces.');
        }
    }

    /**
     * The bucket and folder of `s3://bucket/folder`, or of `bucket/folder`.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function split(?string $value): array
    {
        $value = self::filled($value);

        if ($value === null) {
            return [null, null];
        }

        $path = trim((string) preg_replace('#^s3://#i', '', $value), '/');
        $bucket = strtok($path, '/');
        $prefix = trim(substr($path, strlen((string) $bucket)), '/');

        return [$bucket === false || $bucket === '' ? null : $bucket, $prefix === '' ? null : $prefix];
    }

    /**
     * The AWS_* values of a .env file of the old application; nothing else is taken from it.
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
            throw new RuntimeException('There is no file '.$path.' to read the source bucket from.');
        }

        try {
            $values = Dotenv::parse($content);
        } catch (ExceptionInterface) {
            throw new RuntimeException($path.' is not a valid dotenv file.');
        }

        return array_filter(
            array_map(strval(...), $values),
            static fn (string $name): bool => str_starts_with($name, 'AWS_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The key and region of a profile of the AWS CLI, from ~/.aws/credentials and ~/.aws/config.
     *
     * @return array{key: string|null, secret: string|null, region: string|null}
     */
    private static function fromProfile(?string $profile): array
    {
        if (self::filled($profile) === null) {
            return ['key' => null, 'secret' => null, 'region' => null];
        }

        $home = getenv('HOME') ?: '';
        $credentials = self::ini(getenv('AWS_SHARED_CREDENTIALS_FILE') ?: $home.'/.aws/credentials');
        $config = self::ini(getenv('AWS_CONFIG_FILE') ?: $home.'/.aws/config');
        $section = $credentials[$profile] ?? null;

        if (! is_array($section)) {
            throw new RuntimeException('The AWS profile '.$profile.' is not in ~/.aws/credentials.');
        }

        // In the config file a profile other than the default is written as "profile <name>".
        $settings = $config[$profile === 'default' ? 'default' : 'profile '.$profile] ?? $config[$profile] ?? [];

        return [
            'key' => self::filled(is_string($section['aws_access_key_id'] ?? null) ? $section['aws_access_key_id'] : null),
            'secret' => self::filled(is_string($section['aws_secret_access_key'] ?? null) ? $section['aws_secret_access_key'] : null),
            'region' => self::filled(is_array($settings) && is_string($settings['region'] ?? null) ? $settings['region'] : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ini(string $path): array
    {
        $parsed = is_file($path) ? parse_ini_file($path, true, INI_SCANNER_RAW) : false;

        return is_array($parsed) ? $parsed : [];
    }

    private static function variable(string $name): ?string
    {
        $value = getenv($name);

        return self::filled($value === false ? null : $value);
    }

    private static function filled(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    private static function truthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
