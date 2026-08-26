<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use App\Http\Props\Shared\HelpProps;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use App\Platform\PlaneResolver;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\Devices\Support\AuthenticatorProvisioner;
use Cbox\Id\Devices\Support\EnrolmentToken;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Throwable;

/**
 * MY DEVICES — the personal half of the trusted-devices surface.
 *
 * Enrolment and one's own handsets are account security, like passkeys and TOTP, so this
 * page sits in the My account area and is reachable by every signed-in person. The
 * org-admin fleet inventory — everyone's devices, delivery errors — stays behind the admin
 * gate: that data is reconnaissance, and this page is self-service.
 *
 * EVERY READ AND WRITE IS KEYED TO THE SIGNED-IN SUBJECT, never to a parameter, which is
 * why there is no role check anywhere in it.
 */
final readonly class MyDevicesController extends ConsoleController
{
    public function index(): Response
    {
        /*
         * SELF-PROVISIONING: the first visit registers the authenticator's OAuth client,
         * so enabling the module is a config change and nothing asks anyone to run a
         * command. Any signed-in person may trigger it — the client is deterministic
         * (fixed name, scopes and derived redirect URIs) and carries no secret, so there
         * is nothing a member gains by being first.
         *
         * Failure is reported and swallowed: the page still renders, and the enrolment
         * panel explains itself instead of the whole page becoming a 500.
         */
        if (! AuthenticatorClient::find() instanceof Client) {
            try {
                app(AuthenticatorProvisioner::class)->ensure($this->provisioningHost());
            } catch (Throwable $e) {
                report($e);
            }
        }

        $uri = $this->enrolmentUri();

        return $this->page('devices::mine', 'Trusted devices', [
            'help' => HelpProps::for(HelpTopic::TrustedDevices),
            'enrolment' => $uri === null ? null : [
                'uri' => $uri,
                'qr' => $this->enrolmentQr($uri),
                /*
                 * Seconds between re-renders of the code, comfortably inside its own life
                 * so nobody scans something that lapsed while they were unlocking their
                 * phone. DERIVED from the TTL rather than written as a number, so the two
                 * cannot drift apart.
                 */
                'refreshSeconds' => (int) floor(EnrolmentToken::TTL * 0.75),
            ],
            'appStoreUrl' => $this->appStoreUrl(),
            'devices' => Device::query()
                ->where('subject_id', app(CurrentUser::class)->id())
                ->orderByDesc('last_seen_at')
                ->get()
                ->map(static fn (Device $device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'platform' => $device->platform->label(),
                    'status' => $device->status->label(),
                    'active' => $device->status === DeviceStatus::Active,
                    'lastSeen' => $device->last_seen_at?->diffForHumans(),
                    'removeHref' => route('devices.mine.destroy', $device->id),
                ])->values()->all(),
        ]);
    }

    /**
     * Remove one of MY devices.
     *
     * Same semantics as the API's destroy: SCOPED TO THE CALLER in the query, so somebody
     * else's device behaves exactly like a missing one — and the removal is audited.
     */
    public function destroy(string $device, Request $request): RedirectResponse
    {
        $me = app(CurrentUser::class);

        $model = Device::query()
            ->whereKey($device)
            ->where('subject_id', $me->id())
            ->first();

        // 404, not 403: another person's handset is not a control this reader is failing
        // to press, it is a row they have no business learning exists.
        abort_if($model === null, 404);

        $model->delete();

        app(AuditLog::class)->record(new AuditEvent(
            action: 'device.removed',
            actorType: ActorType::User,
            actorId: $me->id(),
            targetType: 'device',
            targetId: $device,
            ip: $request->ip(),
        ));

        return back()->with('status', 'Device removed.');
    }

    /**
     * The enrolment code the authenticator app scans.
     *
     * Carries the host AND a short-lived signed code bound to the signed-in subject.
     *
     * The host alone used to be the whole payload, described as safe on a screen, in a
     * screenshot, or on a wiki. Safe in the sense that it granted nothing — but it also
     * never expired and named nobody, so a photograph of it stayed a working enrolment
     * pointer indefinitely. The code fixes that: two minutes of life, and the subject
     * travels to `POST /devices` where it is checked against whoever actually signs in.
     *
     * It is NOT an anti-phishing control. The app fetches the verifying key from the host
     * the code names, so a code forged for a hostile host verifies there too. Freshness
     * and binding are the properties on offer; nothing more should be claimed.
     *
     * MINTED ONCE PER RENDER. The page shows it as a QR and as two deep links, and minting
     * per use would hand the phone a different code from the one the QR encodes — a link
     * that silently fails for whoever tapped rather than scanned.
     *
     * Null when provisioning failed above, or when there is somehow no subject: a code
     * bound to nobody could never be spent, so rendering one would be a dead end.
     */
    private function enrolmentUri(): ?string
    {
        if (! AuthenticatorClient::find() instanceof Client) {
            return null;
        }

        $subject = app(CurrentUser::class)->id();

        if ($subject === '') {
            return null;
        }

        return AuthenticatorClient::SCHEME.'://connect'
            .'?host='.urlencode(request()->getHost())
            .'&t='.urlencode(app(EnrolmentToken::class)->mint($subject));
    }

    /**
     * The code as a QR, as a `data:` URI.
     *
     * An `<img>` rather than injected SVG markup, for the same reason the TOTP QR on the
     * security page is one: an image cannot execute what it is handed, and "safe because
     * of where the markup came from" is an argument that survives exactly until somebody
     * moves the call.
     */
    private function enrolmentQr(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 0),
            new SvgImageBackEnd,
        ));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($uri));
    }

    /**
     * The host baked into the claimed-HTTPS redirect URI at first provisioning.
     *
     * Multi-tenant: the request host, which `EnforcePlane` has already resolved to a known
     * tenant — an unrecognised `Host:` header 404s before reaching this page.
     *
     * Single-tenant: the configured `app.url` host, NOT the request host. Every host is
     * served in that shape, so trusting the header would let the first visitor poison the
     * redirect URI with a host they control.
     */
    private function provisioningHost(): string
    {
        return app(PlaneResolver::class)->isMultiTenant()
            ? request()->getHost()
            : AuthenticatorClient::hostFromAppUrl();
    }

    private function appStoreUrl(): ?string
    {
        $url = config('id-devices.app_store_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
