<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Yaml\Yaml;

/**
 * Your own Scaleway Secret Manager, called from this machine with your own
 * key. The variables of an environment travel between this machine and your
 * account; Brewless is not in between and never sees a value.
 */
final class SecretManager
{
    public function __construct(#[SensitiveParameter] private readonly string $secretKey, private readonly string $region, private readonly string $projectId) {}

    /**
     * The Scaleway secret key of this machine: from the environment, or from
     * the configuration of Scaleway's own command line client.
     */
    public static function key(): string
    {
        $key = getenv('SCW_SECRET_KEY');

        if (is_string($key) && $key !== '') {
            return $key;
        }

        $config = (getenv('SCW_CONFIG_PATH') ?: (getenv('HOME') ?: '').'/.config/scw/config.yaml');

        if (is_file($config)) {
            $data = Yaml::parseFile($config);
            $profile = is_array($data) && is_string($data['active_profile'] ?? null) ? ($data['profiles'][$data['active_profile']] ?? []) : $data;

            if (is_array($profile) && is_string($profile['secret_key'] ?? null) && $profile['secret_key'] !== '') {
                return $profile['secret_key'];
            }
        }

        throw new RuntimeException('No Scaleway key on this machine. Set SCW_SECRET_KEY, or sign Scaleway\'s own client in (scw init). The key is used here only; it is not sent to Brewless.');
    }

    /**
     * The identifier of the secret with this name, or null.
     */
    public function find(string $name): ?string
    {
        $answer = $this->send(fn (PendingRequest $request): Response => $request->get($this->base().'/secrets', ['project_id' => $this->projectId, 'name' => $name, 'page_size' => 100]));

        foreach ($answer->json('secrets') ?? [] as $secret) {
            if (($secret['name'] ?? null) === $name) {
                return (string) $secret['id'];
            }
        }

        return null;
    }

    public function create(string $name, string $path): string
    {
        return (string) $this->send(fn (PendingRequest $request): Response => $request->post($this->base().'/secrets', ['project_id' => $this->projectId, 'name' => $name, 'path' => $path]))->json('id');
    }

    /**
     * The newest version: its number and its content. Null when there is none yet.
     *
     * @return array{revision: int, content: string}|null
     */
    public function latest(string $secretId): ?array
    {
        $answer = $this->send(fn (PendingRequest $request): Response => $request->get($this->base().'/secrets/'.$secretId.'/versions/latest/access'), allowMissing: true);

        if ($answer->status() === 404) {
            return null;
        }

        return ['revision' => (int) $answer->json('revision'), 'content' => (string) base64_decode((string) $answer->json('data'), true)];
    }

    /**
     * Write a new version and return its number.
     */
    public function write(string $secretId, #[SensitiveParameter] string $content): int
    {
        return (int) $this->send(fn (PendingRequest $request): Response => $request->post($this->base().'/secrets/'.$secretId.'/versions', ['data' => base64_encode($content)]))->json('revision');
    }

    private function base(): string
    {
        return 'https://api.scaleway.com/secret-manager/v1beta1/regions/'.$this->region;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, bool $allowMissing = false): Response
    {
        try {
            $answer = $call(Http::withHeaders(['X-Auth-Token' => $this->secretKey])->acceptJson()->connectTimeout(10)->timeout(30));
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Scaleway. Check your connection and try again.');
        }

        if ($answer->successful() || ($allowMissing && $answer->status() === 404)) {
            return $answer;
        }

        throw new RuntimeException(match ($answer->status()) {
            401, 403 => 'Scaleway does not accept the key on this machine for Secret Manager in this project. Check SCW_SECRET_KEY and its permissions.',
            default => 'Scaleway answered with an error ('.$answer->status().') for Secret Manager.',
        });
    }
}
