<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Transports;

use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\ValueObjects\PushMessage;
use Cbox\Id\Devices\ValueObjects\PushResult;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * One integration covers both platforms — FCM relays to APNs for iOS — which is why
 * there is no separate APNs driver to maintain, two sets of credentials to rotate, and
 * two error vocabularies to map.
 *
 * ON THE ABSENT SSRF GUARD
 * ------------------------
 * Every other outbound call in this codebase goes through the shared UrlGuard, because
 * every other one targets a URL a TENANT supplied. These two hosts are compiled in and
 * are Google's. Pinning DNS for `oauth2.googleapis.com` protects against nothing an
 * attacker who already controls our DNS could not do more directly, and would add a
 * resolution round trip to the CIBA critical path. The guard is deliberately not used
 * here, and that is a considered omission rather than an oversight.
 *
 * ON THE ACCESS-TOKEN CACHE
 * -------------------------
 * The service-account JWT is exchanged for an access token good for an hour, and that
 * token is cached. Under CACHE_STORE=array the cache is per-process, so every worker
 * re-exchanges on every send: two round trips per push, and Google rate-limits its
 * token endpoint long before FCM complains about the sends themselves. The provider
 * warns at boot when it sees that combination.
 */
final class FcmPushTransport implements PushTransport
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * The only FCM codes that mean THIS TOKEN is dead.
     *
     * Retiring is destructive — it clears the sealed token, so recovery needs the user
     * to open the app again — which makes a false positive far worse than a wasted
     * retry. The list is therefore as narrow as it can be, and three plausible-looking
     * codes are deliberately excluded:
     *
     *  - NOT_FOUND / a bare HTTP 404. A dead token's 404 always carries
     *    `details[].errorCode = UNREGISTERED`, which is matched below. A 404 with NO
     *    detail is the PROJECT-level one — `POST /v1/projects/{wrong-id}/messages:send`
     *    — so treating it as token death means one mistyped `CBOX_ID_DEVICES_FCM_PROJECT_ID`
     *    silently unenrols and wipes the token of every device in the estate on its
     *    first push. That is our misconfiguration, not the handset's: transient.
     *  - INVALID_ARGUMENT. FCM returns this for a malformed MESSAGE — a reserved `data`
     *    key, a non-string value, a body over 4KB — not for a bad token. A future
     *    payload change that trips its validator would otherwise unenrol the fleet
     *    rather than failing one send loudly.
     *
     * @var list<string>
     */
    private const PERMANENT = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $credentialsPath,
        private readonly string $projectId,
        private readonly int $timeout = 10,
    ) {}

    public function send(PushMessage $message): PushResult
    {
        try {
            $accessToken = $this->accessToken();
        } catch (Throwable $e) {
            // A credentials problem is ours, not the handset's — transient, so the
            // notification waits for someone to fix the configuration rather than
            // retiring a perfectly good device.
            return PushResult::transientFailure('FCM auth failed: '.$e->getMessage());
        }

        try {
            $response = $this->http
                ->withToken($accessToken)
                ->connectTimeout(5)
                ->timeout($this->timeout)
                ->withoutRedirecting()
                ->post(
                    "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                    ['message' => $this->envelope($message)],
                );
        } catch (Throwable $e) {
            return PushResult::transientFailure('FCM request failed: '.$e->getMessage());
        }

        if ($response->successful()) {
            return PushResult::delivered($response->status());
        }

        $status = $response->status();
        $error = $this->errorStatus($response->json());

        if ($error !== null && in_array($error, self::PERMANENT, true)) {
            return PushResult::permanentFailure($error, $status);
        }

        return PushResult::transientFailure($error ?? 'FCM returned HTTP '.$status, $status);
    }

    /**
     * The FCM v1 message body.
     *
     * `notification` is what shows on the lock screen and is deliberately vague; `data`
     * carries routing the app re-verifies over TLS. See PushPayload for why that split
     * is a security boundary rather than a formatting one.
     *
     * @return array<string, mixed>
     */
    private function envelope(PushMessage $message): array
    {
        $payload = $message->payload;

        $envelope = [
            'token' => $message->token,
            'notification' => [
                'title' => $payload->title,
                'body' => $payload->body,
            ],
            'data' => $payload->data,
        ];

        // High priority on both platforms: an approval prompt is useless if the OS
        // batches it behind a doze window against a 300-second deadline. A collapse key
        // means a re-push replaces the pending banner rather than stacking a second one
        // for the same decision.
        if ($message->platform === DevicePlatform::Android) {
            $envelope['android'] = array_filter([
                'priority' => 'high',
                'collapse_key' => $payload->collapseKey,
                'notification' => ['channel_id' => $message->kind->value],
            ]);

            return $envelope;
        }

        $envelope['apns'] = [
            'headers' => array_filter([
                'apns-priority' => '10',
                'apns-collapse-id' => $payload->collapseKey,
            ]),
            'payload' => ['aps' => ['interruption-level' => 'time-sensitive', 'sound' => 'default']],
        ];

        return $envelope;
    }

    /**
     * A cached OAuth access token for the service account.
     *
     * Cached for its lifetime less a five-minute margin, so a token never expires
     * mid-flight between the cache read and the FCM call.
     */
    private function accessToken(): string
    {
        $key = 'cbox-id:devices:fcm-token:'.md5($this->credentialsPath.'|'.$this->projectId);

        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->credentials();
        $now = Carbon::now()->getTimestamp();

        $assertion = JWT::encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key'], 'RS256');

        $response = $this->http
            ->connectTimeout(5)
            ->timeout($this->timeout)
            ->asForm()
            ->post(self::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('token endpoint returned HTTP '.$response->status());
        }

        $body = $response->json();

        if (! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            throw new \RuntimeException('token endpoint returned no access_token');
        }

        $token = $body['access_token'];
        $expiresIn = is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 3600;

        $this->cache->put($key, $token, max(60, $expiresIn - 300));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        if (! is_readable($this->credentialsPath)) {
            throw new \RuntimeException('service-account file is not readable');
        }

        $decoded = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (! is_array($decoded)
            || ! is_string($decoded['client_email'] ?? null)
            || ! is_string($decoded['private_key'] ?? null)) {
            throw new \RuntimeException('service-account file is malformed');
        }

        return ['client_email' => $decoded['client_email'], 'private_key' => $decoded['private_key']];
    }

    /**
     * FCM reports the machine-readable reason at
     * `error.details[].errorCode`, with `error.status` as the coarser fallback.
     */
    private function errorStatus(mixed $body): ?string
    {
        if (! is_array($body) || ! is_array($body['error'] ?? null)) {
            return null;
        }

        $error = $body['error'];
        $details = $error['details'] ?? [];

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail) && is_string($detail['errorCode'] ?? null)) {
                    return $detail['errorCode'];
                }
            }
        }

        return is_string($error['status'] ?? null) ? $error['status'] : null;
    }
}
