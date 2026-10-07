import { router } from '@inertiajs/react';
import { Command } from 'cmdk';
import { Fragment, type ReactNode, useMemo, useState } from 'react';
import { cn } from '@/lib/cn';
import type { ContextEnvironment, ContextProject, ShellContext, SwitchOption } from '@/types';
import { Icon, Popover, PopoverContent, PopoverTrigger } from '@/ui';
import { EnvBadge } from './EnvBadge';

/** A menu grows a search box past this many rows — below it, typing is slower than looking. */
export const SEARCH_THRESHOLD = 8;

export interface ContextSwitcherProps {
    context: ShellContext;
    /**
     * How a row that leaves this page is followed. A PAGE LOAD, not an Inertia visit:
     * every environment link goes through the workspace host's `/open/{environment}` and
     * ends on another host, and an Inertia visit is a same-origin XHR that fails there
     * without a word. Injectable so a test can see where a row goes.
     */
    onNavigate?: (href: string) => void;
}

/**
 * WHERE YOU ARE — `Workspace ▾ / Project ▾ / Environment ▾ [PRODUCTION]` — and the way to
 * anywhere else you may go.
 *
 * One control for what was three: an organization switcher on one console, an operator's
 * "target environment" menu on top of it, and on the environment console a back arrow and
 * the environment's name as plain text, so moving from staging to production meant going
 * back to Projects on another host and opening it again.
 *
 * WHAT IS IN EACH MENU IS DECIDED ON THE SERVER ({@see \App\Platform\Console\ShellContext}):
 * only workspaces the person belongs to, and only environments they may administer, each
 * with the link that opens it — on the same page, where that page exists there. This file
 * only arranges them.
 *
 * Each crumb is a button with its own popover rather than one menu with three levels,
 * because the common move is ONE level — the environment beside this one — and a nested
 * menu makes the common move the deepest.
 */
export function ContextSwitcher({ context, onNavigate = defaultNavigate }: ContextSwitcherProps) {
    const workspace = context.workspaces.find((option) => option.current) ?? context.workspaces[0];
    const project = context.projects.find((candidate) => candidate.current);
    const environment = project?.environments.find((candidate) => candidate.current);

    return (
        <nav className="cbx-ctx" aria-label="Context">
            {workspace !== undefined && (
                <WorkspaceCrumb
                    noun={context.noun}
                    current={workspace}
                    workspaces={context.workspaces}
                    switchUrl={context.switchUrl}
                    onNavigate={onNavigate}
                />
            )}

            {project !== undefined && environment !== undefined ? (
                <>
                    <Separator />
                    <ProjectCrumb
                        current={project}
                        environment={environment}
                        projects={context.projects}
                        onNavigate={onNavigate}
                    />
                    <Separator />
                    <EnvironmentCrumb
                        current={environment}
                        project={project}
                        onNavigate={onNavigate}
                    />
                </>
            ) : (
                context.projects.length > 0 && (
                    <>
                        <Separator />
                        <OpenEnvironmentCrumb projects={context.projects} onNavigate={onNavigate} />
                    </>
                )
            )}
        </nav>
    );
}

function defaultNavigate(href: string): void {
    window.location.assign(href);
}

function Separator() {
    return (
        <span className="cbx-ctx-sep" aria-hidden="true">
            /
        </span>
    );
}

/**
 * The first crumb. Choosing another workspace is a POST on the workspace's own console
 * (it re-scopes the session) and a page load from anywhere else, where that session is not
 * this one — the server says which by sending a `switchUrl` or not.
 */
function WorkspaceCrumb({
    noun,
    current,
    workspaces,
    switchUrl,
    onNavigate,
}: {
    noun: string;
    current: SwitchOption;
    workspaces: SwitchOption[];
    switchUrl: string | null;
    onNavigate: (href: string) => void;
}) {
    // A menu that cannot change anything is a control that lies about what it does. With
    // one workspace and no link out, the crumb is a label.
    const actionable = workspaces.length > 1 || current.openHref !== null;

    const tile = (
        <span className="cbx-ctx-tile" aria-hidden="true">
            {current.label.trim().charAt(0).toUpperCase() || 'C'}
        </span>
    );

    if (!actionable) {
        return (
            <span className="cbx-ctx-static" title={`${noun}: ${current.label}`}>
                {tile}
                <span className="cbx-ctx-label">{current.label}</span>
            </span>
        );
    }

    return (
        <ContextMenu
            heading={`Switch ${noun.toLowerCase()}`}
            ariaLabel={`${noun}: ${current.label}. Switch ${noun.toLowerCase()}`}
            trigger={
                <>
                    {tile}
                    <span className="cbx-ctx-label">{current.label}</span>
                </>
            }
            groups={[
                {
                    key: 'workspaces',
                    heading: null,
                    items: workspaces.map((option) => ({
                        key: option.id,
                        label: option.label,
                        hint: option.caption,
                        current: option.current,
                        onSelect: () => {
                            if (option.current && option.openHref === null) {
                                return;
                            }

                            if (switchUrl !== null && !option.current) {
                                router.post(switchUrl, { organization: option.id });

                                return;
                            }

                            if (option.openHref !== null) {
                                onNavigate(option.openHref);
                            }
                        },
                    })),
                },
            ]}
            footnote={
                switchUrl === null && workspaces.length > 1
                    ? `Opens the ${noun.toLowerCase()} console, where you switch.`
                    : null
            }
        />
    );
}

/**
 * The project crumb. Choosing another project opens the environment there that matches
 * the one you are in — production for production — because "show me the other product's
 * production" is the move; landing on whichever environment sorts first is not.
 */
function ProjectCrumb({
    current,
    environment,
    projects,
    onNavigate,
}: {
    current: ContextProject;
    environment: ContextEnvironment;
    projects: ContextProject[];
    onNavigate: (href: string) => void;
}) {
    if (projects.length <= 1) {
        return (
            <span className="cbx-ctx-static" title={`Project: ${current.name}`}>
                <span className="cbx-ctx-label">{current.name}</span>
            </span>
        );
    }

    return (
        <ContextMenu
            heading="Switch project"
            ariaLabel={`Project: ${current.name}. Switch project`}
            trigger={<span className="cbx-ctx-label">{current.name}</span>}
            groups={[
                {
                    key: 'projects',
                    heading: null,
                    items: projects.map((project) => ({
                        key: project.id,
                        label: project.name,
                        hint: `${project.environments.length} environment${project.environments.length === 1 ? '' : 's'}`,
                        current: project.current,
                        onSelect: () => {
                            if (project.current) {
                                return;
                            }

                            const target = counterpart(project, environment);

                            if (target !== undefined) {
                                onNavigate(target.href);
                            }
                        },
                    })),
                },
            ]}
        />
    );
}

/** The environment in `project` that plays the same part `environment` does here. */
export function counterpart(
    project: ContextProject,
    environment: ContextEnvironment,
): ContextEnvironment | undefined {
    return (
        project.environments.find((candidate) => candidate.name === environment.name) ??
        project.environments.find((candidate) => candidate.type === environment.type) ??
        project.environments[0]
    );
}

/** The environment crumb, with the type badge that must never be missed beside it. */
function EnvironmentCrumb({
    current,
    project,
    onNavigate,
}: {
    current: ContextEnvironment;
    project: ContextProject;
    onNavigate: (href: string) => void;
}) {
    const label = (
        <>
            <span className="cbx-ctx-label">{current.name}</span>
            <EnvBadge type={current.type} />
        </>
    );

    if (project.environments.length <= 1) {
        return (
            <span className="cbx-ctx-static" title={`Environment: ${current.name}`}>
                {label}
            </span>
        );
    }

    return (
        <ContextMenu
            heading="Switch environment"
            ariaLabel={`Environment: ${current.name} (${current.type}). Switch environment`}
            trigger={label}
            groups={[
                {
                    key: project.id,
                    heading: null,
                    items: project.environments.map((environment) =>
                        environmentItem(environment, onNavigate),
                    ),
                },
            ]}
            footnote="Opens the same page in the environment you choose."
        />
    );
}

/**
 * On a workspace's own console no environment is current — this console is the workspace
 * — so the second crumb is a door: every environment you may open, grouped by project.
 */
function OpenEnvironmentCrumb({
    projects,
    onNavigate,
}: {
    projects: ContextProject[];
    onNavigate: (href: string) => void;
}) {
    return (
        <ContextMenu
            heading="Open an environment"
            ariaLabel="Open an environment"
            trigger={<span className="cbx-ctx-label cbx-ctx-placeholder">Environments</span>}
            groups={projects.map((project) => ({
                key: project.id,
                heading: project.name,
                items: project.environments.map((environment) =>
                    environmentItem(environment, onNavigate),
                ),
            }))}
        />
    );
}

function environmentItem(
    environment: ContextEnvironment,
    onNavigate: (href: string) => void,
): MenuItem {
    return {
        key: environment.id,
        label: environment.name,
        hint: null,
        badge: <EnvBadge type={environment.type} />,
        current: environment.current,
        onSelect: () => {
            if (!environment.current) {
                onNavigate(environment.href);
            }
        },
    };
}

interface MenuItem {
    key: string;
    label: string;
    hint: string | null;
    badge?: ReactNode;
    current: boolean;
    onSelect: () => void;
}

interface MenuGroup {
    key: string;
    heading: string | null;
    items: MenuItem[];
}

/**
 * One crumb's menu: a popover around a cmdk list, the same pairing the Combobox uses, so
 * every row is reachable with the arrow keys and Enter.
 *
 * THE SEARCH APPEARS ONLY PAST {@see SEARCH_THRESHOLD} ROWS. Most people have one workspace,
 * one or two projects and three environments, and a search box above three rows is a field
 * that has to be tabbed past to reach them. An agency with forty client workspaces is who
 * the box is for, and they get it.
 */
function ContextMenu({
    heading,
    ariaLabel,
    trigger,
    groups,
    footnote = null,
}: {
    heading: string;
    ariaLabel: string;
    trigger: ReactNode;
    groups: MenuGroup[];
    footnote?: string | null;
}) {
    const [open, setOpen] = useState(false);
    const total = useMemo(
        () => groups.reduce((sum, group) => sum + group.items.length, 0),
        [groups],
    );
    const searchable = total > SEARCH_THRESHOLD;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger className="cbx-ctx-trigger" aria-label={ariaLabel}>
                {trigger}
                <Icon name="chevron" className="cbx-ctx-chevron" />
            </PopoverTrigger>

            <PopoverContent className="cbx-ctx-menu" align="start">
                <Command
                    loop
                    label={heading}
                    filter={(value, search, keywords) =>
                        [value, ...(keywords ?? [])]
                            .join(' ')
                            .toLowerCase()
                            .includes(search.toLowerCase())
                            ? 1
                            : 0
                    }
                >
                    {searchable ? (
                        <div className="cbx-combobox-search">
                            <Icon name="search" className="w-4 h-4 shrink-0" />
                            <Command.Input
                                placeholder="Search…"
                                aria-label={`Search — ${heading}`}
                            />
                        </div>
                    ) : (
                        <p className="cbx-menulabel">{heading}</p>
                    )}

                    <Command.List>
                        <Command.Empty className="cbx-combobox-empty">
                            Nothing matches that.
                        </Command.Empty>

                        {groups.map((group) => {
                            const rows = group.items.map((item) => (
                                <Command.Item
                                    key={item.key}
                                    // Unique per row even when two environments share a name
                                    // across projects — cmdk keys selection on the value.
                                    value={`${item.label} ${item.key}`}
                                    keywords={group.heading !== null ? [group.heading] : undefined}
                                    className={cn('cbx-menuitem', item.current && 'is-current')}
                                    aria-current={item.current ? 'true' : undefined}
                                    onSelect={() => {
                                        setOpen(false);
                                        item.onSelect();
                                    }}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate">{item.label}</span>
                                        {item.hint !== null && (
                                            <span className="cbx-menuitem-hint truncate">
                                                {item.hint}
                                            </span>
                                        )}
                                    </span>
                                    {item.badge}
                                    {item.current && (
                                        <Icon
                                            name="check"
                                            className="w-4 h-4 shrink-0"
                                            style={{ color: 'var(--primary)' }}
                                        />
                                    )}
                                </Command.Item>
                            ));

                            return group.heading === null ? (
                                <Fragment key={group.key}>{rows}</Fragment>
                            ) : (
                                <Command.Group
                                    key={group.key}
                                    heading={group.heading}
                                    className="cbx-ctx-group"
                                >
                                    {rows}
                                </Command.Group>
                            );
                        })}
                    </Command.List>

                    {footnote !== null && <p className="cbx-ctx-footnote">{footnote}</p>}
                </Command>
            </PopoverContent>
        </Popover>
    );
}
