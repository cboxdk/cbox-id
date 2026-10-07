<?php

declare(strict_types=1);

namespace App\Platform\Actions;

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
     * Where an action's declared path is mounted below the API root (`/api/v1`).
     *
     * Both OpenAPI documents name that root as their server URL, so this is also what
     * stands in front of an action's path in its document: `/projects` on the workspace
     * plane is `/workspace/projects` there and `/api/v1/workspace/projects` on the wire.
     */
    public function mount(): string
    {
        return match ($this) {
            self::Environment => '',
            self::Workspace => '/workspace',
        };
    }

    /** The action's path as its OpenAPI document (server `/api/v1`) names it. */
    public function documentedPath(string $path): string
    {
        $mounted = $this->mount().($path === '/' ? '' : $path);

        return $mounted === '' ? '/' : $mounted;
    }
}
