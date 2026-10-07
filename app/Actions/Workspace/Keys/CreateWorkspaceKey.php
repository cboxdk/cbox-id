<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Keys;

use App\Actions\Workspace\InWorkspace;
use App\Http\Resources\Workspace\KeyResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\Actions\WorkspaceScopes;
use Carbon\CarbonImmutable;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;

/**
 * Mint a workspace key (`cbid_ws_…`): a role, optionally narrowed by scopes, acting across
 * the whole workspace without a person. The value is in `token`, once.
 *
 * A KEY MAY MINT KEYS, BUT NEVER A WIDER ONE. From another key, the new key's role does not
 * outrank its parent's; its scopes are a subset of the parent's (a parent with scopes
 * cannot mint a role-only key, which would be wider — left out, the parent's scopes are
 * inherited); and it expires no later than the parent. It records its parent, so revoking
 * the parent revokes what it made ({@see RevokeWorkspaceKey}) — an agent handed a narrow
 * key cannot climb out of it by minting itself a broader one.
 */
#[AsAction(
    name: 'keys.workspace.create',
    summary: 'Mint a workspace key with a role and optional scopes, never wider than the caller. The value is returned once, as `token`.',
    scope: 'keys:write',
    danger: Danger::Critical,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/keys'],
    status: 201,
    consoleRoutes: ['keys.workspace.store'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'WorkspaceKey',
    tag: 'Keys',
    redact: ['token'],
)]
final readonly class CreateWorkspaceKey implements Action
{
    public function __construct(private OrganizationApiKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120),
            Field::string('role')->required()->oneOf(array_map(static fn (MembershipRole $role): string => $role->value, MembershipRole::assignable())),
            Field::list('scopes', Field::string('scope')->oneOf(WorkspaceScopes::all()))->nullable()->min(1)->describe('Narrow the key below its role. Left out, the role alone bounds it — or, minted by a key with scopes, that key\'s scopes.'),
            Field::string('expires_at')->nullable()->format('date-time')->describe('When it stops working. Left out, it does not expire — or, minted by a key that does, expires with it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $name = trim($context->string('name'));
        $role = MembershipRole::from($context->string('role'));
        $scopes = $context->has('scopes') && is_array($context->input['scopes'])
            ? array_values(array_unique(array_filter($context->input['scopes'], 'is_string')))
            : null;
        $expiresAt = EnvironmentKeyIssuer::expiry($context, 'expires_at');

        $parent = $context->principal instanceof WorkspaceKeyPrincipal ? $context->principal->key() : null;

        if ($parent !== null) {
            [$scopes, $expiresAt] = $this->bounded($parent, $role, $scopes, $expiresAt);
        }

        $issued = $this->keys->issue($workspaceId, $name, $role, $expiresAt, $scopes, new KeyProvenance(
            createdByType: InWorkspace::creatorType($context->principal),
            createdById: $context->principal->id() !== '' ? $context->principal->id() : null,
            parentKeyId: $parent?->id,
        ));

        // The console's entry exactly, and — only for what the console never sets — the
        // narrowing and the parent, so a key-minted key's trail says where it came from.
        InWorkspace::record($context->principal, $workspaceId, 'organization.api_key_created', 'api_key', $issued->key->id, array_filter([
            'name' => $issued->key->name,
            'role' => $issued->key->role->value,
            'expires_at' => $issued->key->expires_at?->toIso8601String(),
            'scopes' => $scopes,
            'parent_key_id' => $parent?->id,
        ], static fn (mixed $value, string $field): bool => $value !== null || $field === 'expires_at', ARRAY_FILTER_USE_BOTH));

        return ActionResult::item($issued, KeyResource::workspace($issued->key, $issued->plaintext));
    }

    /**
     * The child's scopes and expiry, held inside its parent's.
     *
     * @param  list<string>|null  $scopes
     * @return array{0: list<string>|null, 1: CarbonImmutable|null}
     *
     * @throws ActionRefused
     */
    private function bounded(OrganizationApiKey $parent, MembershipRole $role, ?array $scopes, ?CarbonImmutable $expiresAt): array
    {
        if ($role->outranks($parent->role)) {
            throw ActionRefused::because('role_exceeds_parent', "A key can only mint keys at or below its own role ({$parent->role->value}).", 'role');
        }

        if ($parent->scopes !== null) {
            $scopes ??= $parent->scopes;
            $wider = array_values(array_diff($scopes, $parent->scopes));

            if ($wider !== []) {
                throw ActionRefused::because('scope_exceeds_parent', 'A key can only mint keys within its own scopes. Not held by this key: '.implode(', ', $wider).'.', 'scopes');
            }
        }

        if ($parent->expires_at !== null) {
            $ceiling = CarbonImmutable::instance($parent->expires_at);

            if ($expiresAt === null) {
                $expiresAt = $ceiling;
            } elseif ($expiresAt->greaterThan($ceiling)) {
                throw ActionRefused::because('expiry_exceeds_parent', 'A key-minted key must expire by '.$ceiling->toIso8601String().', when the key minting it does.', 'expires_at');
            }
        }

        return [$scopes, $expiresAt];
    }
}
