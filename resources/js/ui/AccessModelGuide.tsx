import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { Panel } from './Panel';

/** Which of the four this page is. */
export type AccessModel = 'roles' | 'permissions' | 'fga' | 'feature-flags';

interface Entry {
    key: AccessModel | 'entitlements';
    title: string;
    example: string;
    body: string;
    /** The rail page it links to, by route — only drawn where this console has that page. */
    route: string | null;
}

const ENTRIES: Entry[] = [
    {
        key: 'roles',
        title: 'Roles & permissions',
        example: 'An Accountant may invoices:write.',
        body: 'What a person may do in an organization. Assigned per member, carried in the token; your app checks the permission.',
        route: 'roles',
    },
    {
        key: 'fga',
        title: 'Fine-grained authorization',
        example: 'Ada may edit the Q3 folder.',
        body: 'Who may do what to which record. Your app writes the relationships and asks a check on each request.',
        route: 'fga',
    },
    {
        key: 'feature-flags',
        title: 'Feature flags',
        example: 'New dashboard is on for Acme.',
        body: 'Whether a feature is switched on for someone. A rollout, not access control — never guard data with one.',
        route: 'feature-flags',
    },
    {
        key: 'entitlements',
        title: 'Entitlements',
        example: 'Acme’s plan includes SSO.',
        body: 'What an organization has paid for. Set by billing and read from the token — nothing to manage here.',
        route: null,
    },
];

/**
 * ROLES, RELATIONSHIPS, FLAGS OR ENTITLEMENTS? — the four things that all answer "can this
 * person do that?", side by side, with the one this page is marked.
 *
 * They were four pages in three areas, each explaining itself and none the others, so a
 * reader who opened Feature flags to restrict a report, or Roles to give one customer a
 * paid feature, found a page that was not wrong about itself and still sent them the wrong
 * way. This is the comparison every one of those pages now ends with.
 *
 * A link is drawn only to a page on THIS console's rail (read from the shell), so the
 * organization console, which has Roles and Permissions but neither the relationship model
 * nor feature flags, is never offered a page it would 404.
 */
export function AccessModelGuide({ current }: { current: AccessModel }) {
    const { shell } = usePage<SharedProps>().props;
    const pages = (shell?.areas ?? []).flatMap((area) => area.pages);
    const href = (route: string | null): string | undefined =>
        route === null
            ? undefined
            : pages.find((page) => page.route === route || page.route === `environment.${route}`)
                  ?.href;
    const marked = current === 'permissions' ? 'roles' : current;

    return (
        <Panel
            title="Roles, relationships, flags or entitlements?"
            description="Four ways an app decides what someone gets. Pick the one that matches the question your code asks."
            flush
        >
            <ul className="cbx-access-guide">
                {ENTRIES.map((entry) => {
                    const target = entry.key === marked ? undefined : href(entry.route);

                    return (
                        <li
                            key={entry.key}
                            aria-current={entry.key === marked ? 'page' : undefined}
                        >
                            <span className="font-medium">
                                {target !== undefined ? (
                                    <Link href={target}>{entry.title}</Link>
                                ) : (
                                    entry.title
                                )}
                            </span>
                            <p>
                                <em>{entry.example}</em> {entry.body}
                            </p>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}
