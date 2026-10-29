<?php

declare(strict_types=1);

namespace App\Support;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

/**
 * Just enough of S3 for `storage:import --local`: list a bucket, ask for
 * one object's size, fetch one and put one, signed with AWS Signature
 * Version 4. Works against AWS, Scaleway Object Storage and other S3
 * services. A key never goes into a message.
 */
final class S3
{
    /** The largest object one PUT may hold, at AWS and at Scaleway. */
    public const int LARGEST_PUT = 5_000_000_000;

    /** What a fetched object keeps when it is put again. */
    private const array KEPT_HEADERS = ['content-type', 'cache-control', 'content-disposition', 'content-encoding', 'content-language', 'expires'];

    private readonly string $host;

    public function __construct(
        string $endpoint,
        private readonly string $region,
        private readonly string $accessKey,
        #[SensitiveParameter]
        private readonly string $secretKey,
        private readonly bool $pathStyle = false,
    ) {
        $this->host = (string) parse_url(str_contains($endpoint, '://') ? $endpoint : 'https://'.$endpoint, PHP_URL_HOST);
    }

    public static function aws(string $region, string $accessKey, #[SensitiveParameter] string $secretKey): self
    {
        return new self('https://s3.'.$region.'.amazonaws.com', $region, $accessKey, $secretKey);
    }

    /**
     * The region of a bucket at AWS, as AWS says it to anyone who asks;
     * null when it does not answer.
     */
    public static function regionOf(string $bucket): ?string
    {
        try {
            $region = Http::connectTimeout(10)->timeout(20)->head('https://'.$bucket.'.s3.amazonaws.com')->header('x-amz-bucket-region');
        } catch (ConnectionException) {
            return null;
        }

        return $region === '' ? null : $region;
    }

    /**
     * One page of the objects under a folder, and where the next page starts.
     *
     * @return array{objects: list<array{key: string, size: int}>, next: string|null}
     */
    public function list(string $bucket, string $prefix, ?string $from): array
    {
        $query = ['list-type' => '2', 'max-keys' => '1000', ...$prefix === '' ? [] : ['prefix' => $prefix.'/'], ...$from === null ? [] : ['continuation-token' => $from]];
        $response = $this->send('GET', $bucket, '', $query);
        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new RuntimeException('The bucket '.$bucket.' answered with a list that could not be read.');
        }

        $objects = [];

        foreach ($xml->Contents as $object) {
            $key = (string) $object->Key;

            // A folder marker holds nothing.
            if ($key !== '' && ! str_ends_with($key, '/')) {
                $objects[] = ['key' => $key, 'size' => (int) $object->Size];
            }
        }

        $next = (string) $xml->NextContinuationToken;

        return ['objects' => $objects, 'next' => (string) $xml->IsTruncated === 'true' && $next !== '' ? $next : null];
    }

    /**
     * The size of an object, or null when it is not there.
     */
    public function size(string $bucket, string $key): ?int
    {
        $response = $this->send('HEAD', $bucket, $key, allow: [404]);

        return $response->status() === 404 ? null : (int) $response->header('Content-Length');
    }

    /**
     * Fetch an object into a file and return the headers it should be put with.
     *
     * @return array<string, string>
     */
    public function download(string $bucket, string $key, string $path): array
    {
        $response = $this->send('GET', $bucket, $key, stream: true);
        $body = $response->toPsrResponse()->getBody();
        $file = fopen($path, 'wb');

        if ($file === false) {
            throw new RuntimeException('Could not write a temporary file for '.$key.'.');
        }

        while (! $body->eof()) {
            fwrite($file, $body->read(1_048_576));
        }

        fclose($file);

        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $name = strtolower((string) $name);

            if (in_array($name, self::KEPT_HEADERS, true) || str_starts_with($name, 'x-amz-meta-')) {
                $headers[$name] = (string) ($values[0] ?? '');
            }
        }

        return $headers;
    }

    /**
     * Put a file as an object, with the headers it came with.
     *
     * @param  array<string, string>  $headers
     */
    public function upload(string $bucket, string $key, string $path, array $headers): void
    {
        $file = fopen($path, 'rb');

        if ($file === false) {
            throw new RuntimeException('Could not read the temporary file for '.$key.'.');
        }

        $this->send('PUT', $bucket, $key, headers: [...$headers, 'content-length' => (string) filesize($path)], body: $file);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @param  list<int>  $allow  statuses that are an answer, not an error
     * @param  resource|null  $body
     */
    private function send(string $method, string $bucket, string $key, array $query = [], array $headers = [], array $allow = [], bool $stream = false, mixed $body = null): Response
    {
        $host = $this->pathStyle ? $this->host : $bucket.'.'.$this->host;
        $path = '/'.($this->pathStyle ? rawurlencode($bucket).($key === '' ? '' : '/') : '').implode('/', array_map(rawurlencode(...), explode('/', $key)));
        ksort($query);
        $canonicalQuery = implode('&', array_map(static fn (string $name, string $value): string => rawurlencode($name).'='.rawurlencode($value), array_keys($query), $query));

        $signed = $this->signed($method, $host, $path, $canonicalQuery, $headers);
        // With a body the type goes along with it, once.
        $type = $signed['content-type'] ?? 'application/octet-stream';

        if ($body !== null) {
            unset($signed['content-type']);
        }

        $request = Http::connectTimeout(10)->timeout(900)->withHeaders($signed);

        if ($stream) {
            $request = $request->withOptions(['stream' => true]);
        }

        if ($body !== null) {
            $request = $request->withBody(Utils::streamFor($body), $type);
        }

        $url = 'https://'.$host.$path.($canonicalQuery === '' ? '' : '?'.$canonicalQuery);

        try {
            $response = $this->call($request, $method, $url);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach '.$host.'.');
        }

        if ($response->successful() || in_array($response->status(), $allow, true)) {
            return $response;
        }

        $code = preg_match('#<Code>([^<]+)</Code>#', $response->body(), $match) === 1 ? $match[1] : (string) $response->status();

        throw new RuntimeException($host.' refused '.($key === '' ? 'the bucket '.$bucket : $key).': '.$code.'.');
    }

    private function call(PendingRequest $request, string $method, string $url): Response
    {
        return match ($method) {
            'HEAD' => $request->head($url),
            'PUT' => $request->put($url),
            default => $request->get($url),
        };
    }

    /**
     * The headers of a request, Authorization included. The body is not
     * hashed (UNSIGNED-PAYLOAD), which every S3 service takes over https.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function signed(string $method, string $host, string $path, string $query, array $extra): array
    {
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $scope = $date.'/'.$this->region.'/s3/aws4_request';

        $headers = ['host' => $host, 'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD', 'x-amz-date' => $timestamp];

        foreach ($extra as $name => $value) {
            $headers[strtolower($name)] = trim($value);
        }

        ksort($headers);
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $path, $query, ...array_map(static fn (string $name, string $value): string => $name.':'.$value, array_keys($headers), $headers), '', $signedHeaders, 'UNSIGNED-PAYLOAD']);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $timestamp, $scope, hash('sha256', $canonical)]);

        $key = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        unset($headers['host']);

        return [...$headers, 'Authorization' => 'AWS4-HMAC-SHA256 Credential='.$this->accessKey.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.hash_hmac('sha256', $toSign, $key)];
    }
}
