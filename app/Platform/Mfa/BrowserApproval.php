<?php

declare(strict_types=1);

namespace App\Platform\Mfa;

use App\Platform\Actions\Approvals\StepUpClient;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\DeviceApprovalEvidence;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ActionApprovals;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Reuses action approvals; the session holds only the binding for this browser. */
final readonly class BrowserApproval
{
    public function __construct(private ActionApprovals $approvals, private StepUpClient $client) {}

    public function available(string $subject): bool
    {
        return DeviceConfig::bool('id-devices.enabled', false)
            && Device::query()->where('subject_id', $subject)->whereNotNull('dpop_jkt')
                ->where('status', '!=', DeviceStatus::Revoked->value)->exists();
    }

    /** @return array{available: bool, status: string, code?: string, expiresAt?: string} */
    public function respond(Request $request, string $purpose, string $subject, string $anchor, bool $start): array
    {
        if (! $this->available($subject)) {
            return ['available' => false, 'status' => 'unavailable'];
        }

        $digest = $this->digest($request, $purpose, $subject, $anchor);
        $held = $request->session()->get($this->key($purpose));
        if (is_array($held) && ($held['digest'] ?? null) === $digest && is_string($held['id'] ?? null)
            && is_string($held['code'] ?? null) && is_string($held['expiresAt'] ?? null)) {
            $status = $this->approvals->status($held['id']);
            if (! $start || $status === ActionApprovalStatus::Pending
                || ($status === ActionApprovalStatus::Approved && app(DeviceApprovalEvidence::class)->valid($held['id'], $subject))) {
                return ['available' => true, 'status' => ($status === null ? 'expired' : $status->value),
                    'code' => $held['code'], 'expiresAt' => $held['expiresAt']];
            }
        }
        if (! $start) {
            return ['available' => true, 'status' => 'idle'];
        }

        $limit = 'browser-approval:'.app(EnvironmentContext::class)->requireEnvironment()->environmentKey().':'.$subject;
        abort_if(RateLimiter::hit($limit, 300) > 3, 429);
        $code = (string) random_int(100000, 999999);
        $message = ($purpose === 'login' ? 'Confirm sign-in' : 'Confirm security access').' · '.mb_substr($request->getHost(), 0, 210).' · '.$code;
        $approval = $this->approvals->request($this->client->ensure(), $subject, mb_substr($message, 0, 255), $digest, 300);
        $request->session()->put($this->key($purpose), [
            'id' => $approval->requestId, 'digest' => $digest, 'code' => $code,
            'expiresAt' => $approval->expiresAt->format(DATE_ATOM),
        ]);

        return ['available' => true, 'status' => 'pending', 'code' => $code, 'expiresAt' => $approval->expiresAt->format(DATE_ATOM)];
    }

    public function consume(Request $request, string $purpose, string $subject, string $anchor): bool
    {
        return DB::transaction(function () use ($request, $purpose, $subject, $anchor): bool {
            $held = $request->session()->get($this->key($purpose));
            if (! $this->available($subject) || ! is_array($held) || ! is_string($held['id'] ?? null)
                || ($held['digest'] ?? null) !== $this->digest($request, $purpose, $subject, $anchor)
                || ! app(DeviceApprovalEvidence::class)->valid($held['id'], $subject, lock: true)) {
                return false;
            }
            if (! $this->approvals->consume($held['id'], $held['digest'])) {
                return false;
            }
            $request->session()->forget($this->key($purpose));

            return true;
        });
    }

    private function key(string $purpose): string
    {
        return 'cbox.browser_approval.'.$purpose;
    }

    private function digest(Request $request, string $purpose, string $subject, string $anchor): string
    {
        return hash('sha256', json_encode([$purpose, $subject, $anchor, $request->session()->getId(),
            $request->getSchemeAndHttpHost(), app(EnvironmentContext::class)->requireEnvironment()->environmentKey()], JSON_THROW_ON_ERROR));
    }
}
