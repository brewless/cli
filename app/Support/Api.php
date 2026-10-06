<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The api of one organisation, through the door of the command line client:
 * the same api the console uses, with a token instead of a session.
 */
final class Api
{
    public function __construct(private readonly string $organisation, private readonly ?string $token = null) {}

    public function baseUrl(): string
    {
        return config('brewless.scheme').'://'.$this->organisation.'.'.config('brewless.host').'/cli';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->answer(fn (PendingRequest $request): Response => $request->get($this->baseUrl().$path, $query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->answer(fn (PendingRequest $request): Response => $request->post($this->baseUrl().$path, $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     * @return array<string, mixed>
     */
    private function answer(callable $send): array
    {
        $request = Http::acceptJson()->connectTimeout(10)->timeout(60);

        if ($this->token !== null) {
            $request = $request->withToken($this->token);
        }

        try {
            $response = $send($request);
        } catch (ConnectionException) {
            throw new ApiException('Could not reach '.$this->organisation.'.'.config('brewless.host').'. Check the organisation name and your connection.', 0);
        }

        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        throw new ApiException($this->explain($response), $response->status(), is_string($response->json('error')) ? $response->json('error') : null);
    }

    private function explain(Response $response): string
    {
        return match ($response->status()) {
            401 => 'You are not signed in to '.$this->organisation.', or the sign-in has ended. Run: brewless login '.$this->organisation,
            403 => 'You may not do this in '.$this->organisation.'. Deploying takes the owner or admin role.',
            404 => 'Brewless does not know that here. Check the organisation, the application and the environment.',
            423 => 'This organisation is blocked.',
            422 => $this->firstError($response),
            default => is_string($response->json('message')) && $response->status() < 500
                ? $response->json('message')
                : 'Brewless answered with an error ('.$response->status().'). Try again in a moment.',
        };
    }

    /**
     * What the server said about the request, already in words for a person.
     */
    private function firstError(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_array($errors)) {
            foreach ($errors as $messages) {
                if (is_array($messages) && is_string($messages[0] ?? null)) {
                    return $messages[0];
                }
            }
        }

        return is_string($response->json('message')) ? $response->json('message') : 'Brewless refused the request.';
    }
}
