<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use App\ServiceConnections\Contracts\ChecksConnectionEligibility;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Read both product-bound admission decisions; never enroll, buy or borrow a person's grant. */
final class BillingConnectionEligibility implements ChecksConnectionEligibility
{
    public function allows(string $organization, string $consumer): bool
    {
        $url = config('service-access.billing_url');
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && ! str_contains($url, '\\')
            && is_array($parts) && ($parts['scheme'] ?? null) === 'https' && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true), 503, 'Connection eligibility is not configured.');

        foreach (['cbox-sites', 'cbox-assets'] as $service) {
            $token = config('service-connections.admission_tokens.'.$service);
            abort_unless(is_string($token) && trim($token) !== '', 503, 'Connection eligibility is not configured.');
            try {
                $response = Http::acceptJson()->withToken($token)->withoutRedirecting()->connectTimeout(3)->timeout(8)
                    ->post(rtrim($url, '/').'/api/v1/access/services/'.$service.'/check', ['subject' => $consumer, 'organization' => $organization]);
            } catch (ConnectionException) {
                abort(503, 'Connection eligibility is unavailable.');
            }
            $body = $response->json();
            abort_unless($response->status() === 200 && is_array($body) && is_bool($body['allowed'] ?? null)
                && is_string($body['reason'] ?? null) && is_array($body['program'] ?? null)
                && ($body['program']['key'] ?? null) === $service && ($body['program']['kind'] ?? null) === 'service'
                && ($body['program']['scope'] ?? null) === 'organization', 503, 'Connection eligibility could not be verified.');
            if ($body['allowed'] !== true || ! in_array($body['reason'], ['granted', 'subscription'], true)) {
                return false;
            }
        }

        return true;
    }
}
