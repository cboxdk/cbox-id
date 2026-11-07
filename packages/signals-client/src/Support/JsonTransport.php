<?php

declare(strict_types=1);

namespace Cbox\Signals\Support;

use Cbox\Signals\Exceptions\SignalsFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

/**
 * Bearer-authenticated JSON calls with the package's uniform failure
 * vocabulary: `not_configured`, `unavailable`, `malformed_response`, or the
 * server's own `code`. Never redirects, never follows a 3xx to another host.
 */
final readonly class JsonTransport
{
    public function __construct(private Factory $http, private string $url, private float $timeout = 2.0) {}

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $headers
     * @return array<string,mixed>
     *
     * @throws SignalsFailure
     */
    public function send(string $method, string $path, string $token, array $body = [], array $headers = []): array
    {
        return $this->decode($this->response($method, $path, $token, $body, $headers));
    }

    /**
     * Like {@see send()} but a 404 carrying one of the given codes yields null.
     *
     * @param  list<string>  $absentCodes
     * @return array<string,mixed>|null
     *
     * @throws SignalsFailure
     */
    public function sendOrAbsent(string $method, string $path, string $token, array $absentCodes): ?array
    {
        $response = $this->response($method, $path, $token, [], []);
        if ($response->status() === 404) {
            $data = $response->json();
            if (is_array($data) && in_array($data['code'] ?? null, $absentCodes, true)) {
                return null;
            }
        }

        return $this->decode($response);
    }

    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $headers
     */
    private function response(string $method, string $path, string $token, array $body, array $headers): Response
    {
        if ($this->url === '' || $token === '') {
            throw new SignalsFailure('not_configured', 503);
        }
        try {
            $request = $this->http->withToken($token)->acceptJson()->timeout($this->timeout)->connectTimeout(min($this->timeout, 1))->withoutRedirecting()->withHeaders($headers);
            $target = rtrim($this->url, '/').$path;

            return match ($method) {
                'GET' => $request->get($target, $body),
                'PUT' => $request->put($target, $body),
                'DELETE' => $request->delete($target, $body),
                default => $request->post($target, $body),
            };
        } catch (ConnectionException) {
            throw new SignalsFailure('unavailable', 503);
        }
    }

    /** @return array<string,mixed> */
    private function decode(Response $response): array
    {
        $data = $response->json();
        if (! $response->successful()) {
            throw new SignalsFailure(is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : 'unavailable', $response->status());
        }

        return Wire::object($data);
    }
}
