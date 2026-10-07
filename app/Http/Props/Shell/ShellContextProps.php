<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;
use App\Platform\Console\ShellContext;

/**
 * WHERE YOU ARE, as the topbar draws it: `Workspace ▾ / Project ▾ / Environment ▾`.
 *
 * Built by {@see ShellContext}. Three lists rather than one tree with a cursor in it,
 * because the three are answered differently: the workspaces are every organization the
 * person belongs to; the projects and environments are only the current workspace's, and
 * only the environments this person may administer.
 */
final readonly class ShellContextProps implements Prop
{
    /**
     * @param  list<SwitchOptionProps>  $workspaces  always holds the current one, even alone
     * @param  list<ContextProjectProps>  $projects  empty where the organization owns none
     */
    public function __construct(
        /**
         * What the first crumb is called. "Workspace" where the organization owns
         * projects — a customer of the install — and "Organization" everywhere else, where
         * there is no project or environment above it to switch between.
         */
        public string $noun,
        public array $workspaces,
        /**
         * Where choosing another workspace POSTs, and null where it cannot be done from
         * here: on an environment's own host the session that holds a workspace is not
         * this one, so each row links to the workspace console instead.
         */
        public ?string $switchUrl,
        public array $projects,
    ) {}

    /**
     * @return array{noun: string, workspaces: list<SwitchOptionProps>, switchUrl: string|null, projects: list<ContextProjectProps>}
     */
    public function toArray(): array
    {
        return [
            'noun' => $this->noun,
            'workspaces' => $this->workspaces,
            'switchUrl' => $this->switchUrl,
            'projects' => $this->projects,
        ];
    }
}
