<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Apis\ApiAudit;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * The one shape a change to HOW PEOPLE SIGN IN takes in the audit trail.
 *
 * None of these changes recorded anything before they became actions: {@see AuthPolicies}
 * writes no entry, a SAML application was a bare model save, and the legacy-login approval
 * kept who-and-when on its own row and nowhere else. That was a gap with a person in the
 * loop; with a management key able to make the same change it is the change nobody can
 * account for afterwards — a key that turned off the MFA requirement, or pointed passwords
 * at another URL, must be on the trail as the key that did it. So the actions record here,
 * and the console, running the same actions, records the same entries.
 *
 * Mirrors {@see ApiAudit}: on the organization's trail when the change is one
 * organization's, the environment's system trail when it is the environment's own. What is
 * never in the context is a secret — a provider's client secret, a certificate.
 *
 * Enabling a social provider is not here because the framework already records it
 * ({@see Connections::activate()} writes `connection.activated`).
 */
final readonly class SignInAudit
{
    public const string POLICY_UPDATED = 'auth_policy.updated';

    public const string POLICY_INHERITED = 'auth_policy.inherited';

    public const string SMS_POLICY_UPDATED = 'auth_policy.sms_updated';

    public const string SOCIAL_PROVIDER_REMOVED = 'social_provider.removed';

    public const string SAML_APP_REGISTERED = 'saml_app.registered';

    public const string SAML_APP_UPDATED = 'saml_app.updated';

    public const string SAML_APP_DELETED = 'saml_app.deleted';

    public const string LEGACY_LOGIN_APPROVED = 'legacy_login.approved';

    public const string LEGACY_LOGIN_REVOKED = 'legacy_login.revoked';

    public function __construct(private AuditLog $log) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, AuditActor $actor, ?string $organizationId, string $targetType, ?string $targetId, array $context = []): void
    {
        $this->log->record(new AuditEvent(
            action: $action,
            actorType: $actor->type,
            actorId: $actor->id,
            organizationId: $organizationId,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
        ));
    }
}
