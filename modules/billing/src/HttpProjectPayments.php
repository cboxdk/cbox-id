<?php

declare(strict_types=1);

namespace Cbox\Id\Billing;

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpProjectPayments implements ProjectPayments
{
    public function subscription(string $organization, string $project): ?array
    {
        $response = $this->request()->get('/api/v1/subscriptions/'.rawurlencode($organization), ['reference' => $project]);
        if ($response->status() === 404 && is_string($response->json('error'))) {
            return null;
        }

        return $this->body($response);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function perform(string $operation, string $organization, string $project, array $input, string $idempotencyKey): array
    {
        $path = match ($operation) {
            'subscribe' => '/api/v1/subscriptions',
            'checkout' => '/api/v1/checkout-sessions',
            'preview', 'change', 'cancel', 'reactivate' => '/api/v1/subscriptions/'.rawurlencode($organization).'/'.$operation,
            'cancel-preview' => '/api/v1/subscriptions/'.rawurlencode($organization).'/cancel/preview',
            default => throw new RuntimeException('Unknown payment operation.'),
        };

        return $this->body($this->request()->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post($path, ['org' => $organization, 'reference' => $project] + $input));
    }

    private function request(): PendingRequest
    {
        $url = config('billing-client.base_url');
        $token = config('billing-client.api_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw TransportException::malformed('project-subscription', 'payments are not configured');
        }

        return Http::baseUrl(rtrim($url, '/'))->acceptJson()->asJson()->withToken($token)
            ->withoutRedirecting()->connectTimeout(3)->timeout(Config::integer('billing-client.timeout', 10));
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        if (! $response->successful()) {
            throw TransportException::fromResponse('project-subscription', $response);
        }
        $body = $response->json();
        if (! is_array($body) || array_is_list($body)) {
            throw TransportException::malformed('project-subscription', 'expected an object');
        }

        return array_filter($body, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
