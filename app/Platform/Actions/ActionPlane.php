<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Http\Middleware\AuthenticateDelegatedApi;
use App\Platform\Actions\Principal\OperatorPrincipal;
use App\Platform\Actions\Principal\PersonPrincipal;

/**
 * Where an action is served: which host its REST route lives on and which credential
 * reaches it. One plane per action — an action that means something on two planes is two
 * actions.
 */
enum ActionPlane: string
{
    /** One environment, on its own host: `/api/v1/…` with an environment key. */
    case Environment = 'environment';

    /**
     * A workspace — the customer above every environment it owns: `/api/v1/workspace/…`
     * with a workspace key (`cbid_ws_…`), resolving no environment.
     */
    case Workspace = 'workspace';

    /**
     * The deployment itself — Cbox staff running the install: `/api/v1/platform/…`, served
     * on the platform-root host. NO KEY OF ANY KIND reaches it: only a person who is a
     * platform operator, in the console or through a token they delegated
     * ({@see OperatorPrincipal}) carrying `operator:*` scopes.
     */
    case Platform = 'platform';

    /**
     * One person's own account — their profile, sessions, app grants, personal keys and
     * devices: `/api/v1/me/…` on the host of the environment they belong to. Only that
     * person reaches it, in the console or through a token they delegated
     * ({@see PersonPrincipal}); no management key acts as a person.
     */
    case Account = 'account';

    /**
     * Where an action's declared path is mounted below the API root (`/api/v1`).
     *
     * Every OpenAPI document names that root as its server URL, so this is also what
     * stands in front of an action's path in its document: `/projects` on the workspace
     * plane is `/workspace/projects` there and `/api/v1/workspace/projects` on the wire.
     */
    public function mount(): string
    {
        return match ($this) {
            self::Environment => '',
            self::Workspace => '/workspace',
            self::Platform => '/platform',
            self::Account => '/me',
        };
    }

    /** The action's path as its OpenAPI document (server `/api/v1`) names it. */
    public function documentedPath(string $path): string
    {
        $mounted = $this->mount().($path === '/' ? '' : $path);

        return $mounted === '' ? '/' : $mounted;
    }

    /**
     * Whether only a PERSON reaches this plane — never a management key. Its REST door
     * takes a token the person delegated ({@see AuthenticateDelegatedApi}); until such
     * tokens are issued, the console is the only way in.
     */
    public function personal(): bool
    {
        return $this === self::Platform || $this === self::Account;
    }
}
