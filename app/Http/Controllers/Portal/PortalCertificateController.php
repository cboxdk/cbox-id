<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Sso\ActivateSsoCertificate;
use App\Actions\Sso\StageSsoCertificate;
use App\Platform\Enums\PortalIntent;
use App\Platform\Sso\ConnectionCertificates;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * SAML CERTIFICATE RENEWAL IN THE ADMIN PORTAL — the three moves of a renewal with no outage
 * in it ({@see ConnectionCertificates}), each one a step on the page:
 *
 *  1. upload the identity provider's new certificate, or its metadata — TESTED before it is
 *     trusted, and the page shows what was tested;
 *  2. switch the identity provider over to signing with it (both are trusted meanwhile);
 *  3. activate it here, which retires the old one.
 *
 * Every SAML connection of the organization is listed with when it stops working, so the
 * person can see which one the alert they received was about.
 */
final readonly class PortalCertificateController extends PortalController
{
    public function show(ConnectionCertificates $certificates): Response
    {
        $this->requireIntent(PortalIntent::CertificateRenewal);

        $connections = Connection::query()
            ->where('organization_id', $this->organizationId())
            ->whereNull('provider')
            ->where('type', ConnectionType::Saml)
            ->orderByDesc('created_at')
            ->get()
            // A draft still waiting for its identity provider has no certificate to renew.
            ->filter(static fn (Connection $connection): bool => $certificates->all($connection) !== []);

        return $this->portalPage('portal/certificates', __('portal.certificates.title'), [
            'connections' => $connections->map(static function (Connection $connection) use ($certificates): array {
                $effective = $certificates->effectiveExpiry($connection);

                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'active' => $connection->isActive(),
                    'expiresAt' => $effective?->notAfter->toIso8601ZuluString(),
                    'daysRemaining' => $effective?->daysRemaining(),
                    'certificates' => ConnectionCertificates::present($certificates->all($connection)),
                    'stageHref' => route('portal.certificates.stage', $connection->id),
                    'activateHref' => route('portal.certificates.activate', $connection->id),
                ];
            })->values()->all(),
        ]);
    }

    public function stage(Request $request, string $connection): RedirectResponse
    {
        $this->requireIntent(PortalIntent::CertificateRenewal);

        $certificate = trim($request->string('certificate')->toString());
        $metadata = trim($request->string('metadata')->toString());

        $result = $this->act(StageSsoCertificate::class, [
            'id' => $connection,
            'organization_id' => $this->organizationId(),
            'certificate' => $certificate === '' ? null : $certificate,
            'metadata' => $metadata === '' ? null : $metadata,
        ], ['certificate' => 'certificate', 'metadata' => 'metadata'], 'certificate');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $this->inertia->flash('certificateChecks', [
            'connectionId' => $connection,
            'checks' => $result->payload['checks'] ?? [],
        ]);

        return back()->with('status', __('portal.certificates.staged'));
    }

    public function activate(Request $request, string $connection): RedirectResponse
    {
        $this->requireIntent(PortalIntent::CertificateRenewal);

        $result = $this->act(ActivateSsoCertificate::class, [
            'id' => $connection,
            'organization_id' => $this->organizationId(),
            'fingerprint_sha256' => $request->string('fingerprint')->toString(),
        ], ['fingerprint_sha256' => 'fingerprint'], 'fingerprint');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.certificates.activated'));
    }
}
