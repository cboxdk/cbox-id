import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, usePage } from '@inertiajs/react';
import { rail } from '@/lib/rail';
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

const ENTRIES = (t: Translator['t']): Entry[] => [
    {
        key: 'roles',
        title: t('console.roles_permissions_9d598ceb'),
        example: t('console.an_accountant_may_invoices_write_5f99429d'),
        body: t('console.what_a_person_may_do_in_an_organization_assi_3fc83055'),
        route: 'roles',
    },
    {
        key: 'fga',
        title: t('console.fine_grained_authorization_9fde1ed0'),
        example: t('console.ada_may_edit_the_q3_folder_839b4fe8'),
        body: t('console.who_may_do_what_to_which_record_your_app_wri_ca6c6d61'),
        route: 'fga',
    },
    {
        key: 'feature-flags',
        title: t('console.feature_flags_20a2e59b'),
        example: t('console.new_dashboard_is_on_for_acme_29525b4d'),
        body: t('console.whether_a_feature_is_switched_on_for_someone_631c0d5f'),
        route: 'feature-flags',
    },
    {
        key: 'entitlements',
        title: t('console.entitlements_2cc79c9e'),
        example: t('console.acme_s_plan_includes_sso_73fa3dd8'),
        body: t('console.what_an_organization_has_paid_for_set_by_bil_fc59c3a4'),
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
    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const pages = rail(usePage<SharedProps>().props).areas.flatMap((area) => area.pages);
    const href = (route: string | null): string | undefined =>
        route === null
            ? undefined
            : pages.find((page) => page.route === route || page.route === `environment.${route}`)
                  ?.href;
    const marked = current === 'permissions' ? 'roles' : current;

    return (
        <Panel
            title={consoleT('console.roles_relationships_flags_or_entitlements_9443a29b')}
            description={consoleT(
                'console.four_ways_an_app_decides_what_someone_gets_pick_the_one_19708f9f',
            )}
            flush
        >
            <ul className="cbx-access-guide">
                {ENTRIES(consoleOptionText).map((entry) => {
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
