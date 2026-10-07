<?php

declare(strict_types=1);

namespace App\Actions\SupportSessions;

use App\Http\Resources\Environment\SupportSessionResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\SupportSessions;
use Cbox\Id\OAuthServer\Enums\SupportActorKind;
use Cbox\Id\OAuthServer\Enums\SupportSessionRefusal;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Exceptions\SupportSessionRefused;
use Cbox\Id\OAuthServer\ValueObjects\NewSupportSession;
use Cbox\Id\OAuthServer\ValueObjects\SupportCodeRequest;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Validation\ValidationException;

/**
 * A member of your staff signs in to your app AS one of a customer's users, for a stated
 * reason and at most an hour — {@see SupportSessions::begin()}, with the session's first
 * authorization code when the request asks for one (a `redirect_uri` and an S256 PKCE
 * `code_challenge`).
 *
 * ALWAYS AS STAFF. The actor is the person named in `actor_user_id`, and the framework checks
 * them the way it checks every staff actor: they must hold THIS app's own
 * `support:impersonate` through an environment-wide grant. A key asserts nothing on their
 * behalf — `SupportActorKind::EnvironmentAdmin`, which the framework takes on the caller's
 * word, is the console's, and the console's own "sign in as" hands the administrator's
 * BROWSER to the app, which no API can do.
 *
 * The framework audits the session on both trails (the customer's and the environment's)
 * and announces `support_session.started` to the organization's webhooks.
 *
 * CRITICAL, and the code is a credential: it is shown once and never kept for an idempotent
 * replay.
 */
#[AsAction(
    name: 'support_sessions.start',
    summary: 'Start a support session: a staff member holding the app\'s support:impersonate acts as a customer\'s user in that app, for a reason and at most an hour. Optionally returns the first authorization code.',
    scope: 'support:write',
    danger: Danger::Critical,
    schema: 'SupportSession',
    tag: 'Support sessions',
    rest: ['POST', '/support-sessions'],
    status: 201,
    redact: ['code'],
)]
final readonly class StartSupportSession implements Action
{
    public function __construct(private SupportSessions $sessions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('user_id')->required()->max(64)->describe('The customer\'s user to act as.'),
            Field::string('organization_id')->required()->max(64)->describe('The organization they are acted as in — one they are an active member of.'),
            Field::string('client_id')->required()->max(255)->describe('The first-party app the session signs in to.'),
            Field::string('actor_user_id')->required()->max(64)->describe('The staff member acting. They must hold the app\'s support:impersonate everywhere.'),
            Field::string('reason')->required()->max(500)->describe('Why — on both audit trails and shown to the customer.'),
            Field::integer('ttl_minutes')->min(1)->max(60)->describe('How long it lasts. Default and maximum: the configured limit.'),
            Field::list('scopes', Field::string('scope')->max(128))->max(50)->describe('The scopes its tokens carry; settled to what one API can be audienced.'),
            Field::string('redirect_uri')->max(2048)->describe('With `code_challenge`: return the session\'s first authorization code for this redirect URI.'),
            Field::string('code_challenge')->max(43)->describe('An S256 PKCE challenge: 43 base64url characters.'),
            Field::string('nonce')->nullable()->max(255),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = $context->string('organization_id');

        if (Organization::query()->whereKey($organizationId)->doesntExist()) {
            throw ActionRefused::because('organization_not_found', 'No organization with that organization_id exists in this environment.', 'organization_id');
        }

        $code = $this->codeRequest($context);
        $ttl = $context->input['ttl_minutes'] ?? null;

        try {
            $started = $this->sessions->begin(new NewSupportSession(
                actorId: $context->string('actor_user_id'),
                actorKind: SupportActorKind::Staff,
                targetUserId: $context->string('user_id'),
                organizationId: $organizationId,
                clientId: $context->string('client_id'),
                reason: $context->string('reason'),
                scopes: array_values(array_filter($context->array('scopes'), 'is_string')),
                ttlSeconds: is_numeric($ttl) ? (int) $ttl * 60 : null,
            ), $code);
        } catch (InvalidAudience $audience) {
            // Scopes of two registered APIs cannot be audienced to one token, so no code this
            // session minted could be redeemed: refused before anything starts.
            throw ActionRefused::because($audience->error, $audience->getMessage(), 'scopes');
        } catch (SupportSessionRefused $refused) {
            throw new ActionRefused($refused->refusal->value, $refused->getMessage(), match ($refused->refusal) {
                SupportSessionRefusal::NotPermitted => 403,
                SupportSessionRefusal::OrganizationInactive,
                SupportSessionRefusal::SessionNotActive => 409,
                default => 422,
            });
        }

        return ActionResult::item($started, SupportSessionResource::from($started->session, $started->code, $code?->redirectUri));
    }

    /**
     * The first code, when one was asked for — a redirect URI and a PKCE challenge, both or
     * neither.
     *
     * @throws ValidationException
     */
    private function codeRequest(ActionContext $context): ?SupportCodeRequest
    {
        $redirectUri = $context->nullableString('redirect_uri');
        $challenge = $context->nullableString('code_challenge');

        if ($redirectUri === null && $challenge === null) {
            return null;
        }

        if ($redirectUri === null) {
            throw ValidationException::withMessages(['redirect_uri' => 'The redirect_uri is required with a code_challenge.']);
        }

        if ($challenge === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
            throw ValidationException::withMessages(['code_challenge' => 'The code_challenge must be an S256 PKCE challenge: 43 base64url characters.']);
        }

        return new SupportCodeRequest($redirectUri, $challenge, $context->nullableString('nonce'));
    }
}
