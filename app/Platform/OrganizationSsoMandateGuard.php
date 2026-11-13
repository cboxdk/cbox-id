<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;

/**
 * An organization may require single sign-on only while it has a connection to sign in
 * through.
 *
 * "SSO required" closes every other door to the organization's members. With no active
 * connection of the organization's own there is no door left, and the members are
 * stranded: signed out, and refused at every local sign-in, for as long as the mandate
 * stands. A member who also belongs to other organizations is stranded out of THOSE as
 * well, because on a per-person deployment one membership's mandate closes local sign-in
 * for the person everywhere ({@see PlatformAuth::localSignInAllowedFor()}). One
 * organization's administrator must not be able to do that to people whose other
 * organizations are none of their business.
 *
 * {@see RootSignInGuard} has asked the same question for the platform's operators all along;
 * this asks it for every member, in every environment.
 */
final readonly class OrganizationSsoMandateGuard
{
    public function __construct(private AuthPolicies $policies) {}

    /**
     * Refuse making SSO required for the organization unless it has an active connection.
     *
     * @param  list<string>  $connectionsGone  connections this change takes away (disabling or deleting one)
     */
    public function assertConnectionBehindMandate(string $organizationId, ?string $field = 'sso', array $connectionsGone = []): void
    {
        if ($this->hasActiveConnection($organizationId, $connectionsGone)) {
            return;
        }

        throw ActionRefused::onFields('no_active_connection', [
            $field ?? 'sso' => 'Activate a single sign-on connection for this organization before requiring single sign-on: without one its members would have no way to sign in.',
        ]);
    }

    /**
     * Refuse taking away the organization's last active connection while its own policy
     * requires single sign-on.
     */
    public function assertMandateKeepsAConnection(?string $organizationId, string $connectionId): void
    {
        if ($organizationId === null) {
            return;
        }

        $override = $this->policies->overrideFor($organizationId);

        if ($override === null || $override->sso->allowsPasswordLogin()) {
            return;
        }

        if ($this->hasActiveConnection($organizationId, [$connectionId])) {
            return;
        }

        throw ActionRefused::onFields('mandate_needs_connection', [
            'id' => 'This organization requires single sign-on and this is its last active connection. Stop requiring single sign-on first, so its members keep a way to sign in.',
        ]);
    }

    /** @param  list<string>  $except */
    private function hasActiveConnection(string $organizationId, array $except = []): bool
    {
        return Connection::query()
            ->where('organization_id', $organizationId)
            ->where('status', ConnectionStatus::Active)
            ->when($except !== [], static fn ($query) => $query->whereNotIn('id', $except))
            ->exists();
    }
}
