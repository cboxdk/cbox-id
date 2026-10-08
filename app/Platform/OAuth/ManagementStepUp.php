<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Http\Controllers\Api\ActionController;
use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\McpProtectedResources;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\TokenAuthenticated;
use App\Platform\OAuth\Exceptions\StepUpAuthenticationRequired;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Id\OAuthServer\Support\BearerChallenge;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationAssessment;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationRequirement;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use InvalidArgumentException;

/**
 * RFC 9470 STEP-UP ON THE MANAGEMENT PLANE: how recent and how strong the sign-in behind a
 * person's token must be before it may run a Critical action — `config('api.mcp.step_up')`.
 *
 * ONE RULE, BOTH DOORS. {@see ActionRunner} asks it of every run, so the REST door
 * ({@see ActionController}) answers `401` with the challenge; `/mcp` asks it at the HTTP
 * layer ({@see AuthenticateMcp}) before a `tools/call` reaches a tool, because a tool's
 * failure is a JSON-RPC result inside a 200 and an MCP client re-authorizes on a 401's
 * `WWW-Authenticate`, never on a tool error.
 *
 * EVALUATED BY THE FRAMEWORK ({@see AuthenticationRequirement::assessToken()}), the rule
 * `/authorize` applies when it decides whether the person must sign in again or add a
 * factor — so a token the authorization server issued for the challenged requirement is a
 * token this accepts, and a client can never loop between the two.
 *
 * WHAT IT ASKS AND WHAT IT DOES NOT. Only a principal that IS a person's token
 * ({@see TokenAuthenticated}); a management key is not a sign-in and is governed by its
 * own approval policy. Only {@see Danger::Critical} — the actions that mint credentials,
 * take over accounts or change how people sign in — and only when a requirement is
 * configured. It runs BEFORE the approval hold: there is no point asking the person to
 * approve on their device a call that would then be refused for its token.
 */
final readonly class ManagementStepUp
{
    public function __construct(private ProtectedResources $resources) {}

    /**
     * The configured requirement, or null when step-up is off.
     *
     * @throws InvalidArgumentException for an `acr` this server does not assert — a typo
     *                                  must not quietly demand nothing
     */
    public static function requirement(): ?AuthenticationRequirement
    {
        $acr = config('api.mcp.step_up.acr');
        $maxAge = config('api.mcp.step_up.max_age');

        $class = is_string($acr) && trim($acr) !== '' ? self::class(trim($acr)) : null;
        $seconds = is_int($maxAge) || (is_string($maxAge) && ctype_digit($maxAge)) ? (int) $maxAge : null;

        return $class === null && $seconds === null ? null : AuthenticationRequirement::of($class, $seconds);
    }

    /**
     * The shortfall, when this principal's token does not meet the requirement for this
     * action — or null when it does, or nothing is asked of it.
     */
    public function assess(Principal $principal, ActionDefinition $action): ?AuthenticationAssessment
    {
        if ($action->danger !== Danger::Critical || ! $principal instanceof TokenAuthenticated) {
            return null;
        }

        $requirement = self::requirement();

        if ($requirement === null) {
            return null;
        }

        // A principal built without its token cannot show a sign-in: assessed as one with
        // neither fact, which the framework fails closed.
        $assessment = $requirement->assessToken($principal->token() ?? Introspection::active(null, null, [], []));

        return $assessment->isSatisfied() ? null : $assessment;
    }

    /** @throws StepUpAuthenticationRequired */
    public function enforce(Principal $principal, ActionDefinition $action): void
    {
        $assessment = $this->assess($principal, $action);

        if ($assessment !== null) {
            throw new StepUpAuthenticationRequired($assessment);
        }
    }

    /**
     * The RFC 9470 §3 challenge, on this host's management-plane resource so the client
     * also learns where to sign in (RFC 9728 `resource_metadata`).
     */
    public function challenge(AuthenticationAssessment $assessment): BearerChallenge
    {
        $resource = $this->resources->forMetadataPath('/.well-known/oauth-protected-resource'.McpProtectedResources::PATH);

        return $assessment->challenge($resource === null ? null : BearerChallenge::for($resource)) ?? new BearerChallenge;
    }

    /** ` with a second factor, within 900 seconds` — the requirement, said for a person. */
    public static function describe(AuthenticationRequirement $requirement): string
    {
        $parts = [];

        if ($requirement->requiredClass() === AuthenticationContextClass::Aal2) {
            $parts[] = ' with a second factor';
        }

        if ($requirement->maxAge !== null) {
            $parts[] = ' within the last '.$requirement->maxAge.' seconds';
        }

        return implode(',', $parts);
    }

    private static function class(string $acr): string
    {
        return match (strtolower($acr)) {
            'aal2', 'mfa' => AuthenticationContextClass::Aal2->value,
            'aal1', 'pwd' => AuthenticationContextClass::Aal1->value,
            default => AuthenticationContextClass::tryFrom($acr)?->value
                ?? throw new InvalidArgumentException('api.mcp.step_up.acr must be aal1, aal2 (mfa) or one of: '.implode(', ', AuthenticationContextClass::values()).". Got: {$acr}."),
        };
    }
}
