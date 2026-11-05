import { useUiTranslator } from '@/i18n/ui';
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
    const { t: tc } = useUiTranslator();
    const ENTRIES: Entry[] = [
        {
            key: 'roles',
            title: tc('console.roles_permissions_9d598ceb', 'Roles & permissions'),
            example: tc(
                'console.an_accountant_may_invoices_write_5f99429d',
                'An Accountant may invoices:write.',
            ),
            body: tc(
                'console.what_a_person_may_do_in_an_organization_assi_3fc83055',
                'What a person may do in an organization. Assigned per member, carried in the token; your app checks the permission.',
            ),
            route: 'roles',
        },
        {
            key: 'fga',
            title: tc('console.fine_grained_authorization_9fde1ed0', 'Fine-grained authorization'),
            example: tc(
                'console.ada_may_edit_the_q3_folder_839b4fe8',
                'Ada may edit the Q3 folder.',
            ),
            body: tc(
                'console.who_may_do_what_to_which_record_your_app_wri_ca6c6d61',
                'Who may do what to which record. Your app writes the relationships and asks a check on each request.',
            ),
            route: 'fga',
        },
        {
            key: 'feature-flags',
            title: tc('console.feature_flags_20a2e59b', 'Feature flags'),
            example: tc(
                'console.new_dashboard_is_on_for_acme_29525b4d',
                'New dashboard is on for Acme.',
            ),
            body: tc(
                'console.whether_a_feature_is_switched_on_for_someone_631c0d5f',
                'Whether a feature is switched on for someone. A rollout, not access control \u2014 never guard data with one.',
            ),
            route: 'feature-flags',
        },
        {
            key: 'entitlements',
            title: tc('console.entitlements_2cc79c9e', 'Entitlements'),
            example: tc(
                'console.acme_s_plan_includes_sso_73fa3dd8',
                'Acme\u2019s plan includes SSO.',
            ),
            body: tc(
                'console.what_an_organization_has_paid_for_set_by_bil_fc59c3a4',
                'What an organization has paid for. Set by billing and read from the token \u2014 nothing to manage here.',
            ),
            route: null,
        },
    ];

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
            title={tc(
                'console.roles_relationships_flags_or_entitlements_9443a29b',
                'Roles, relationships, flags or entitlements?',
            )}
            description={tc(
                'console.four_ways_an_app_decides_what_someone_gets_p_19708f9f',
                'Four ways an app decides what someone gets. Pick the one that matches the question your code asks.',
            )}
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
