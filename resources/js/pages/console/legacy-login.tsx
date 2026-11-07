import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    ConfirmDelete,
    EmptyState,
    Field,
    Input,
    Kv,
    KvList,
    PageHeader,
    Panel,
    Pill,
} from '@/ui';

type Props = PageProps<{
    declaration: {
        url: string;
        approved: boolean;
        approvedAt: string | null;
        /** The app that declared it, or null when that app no longer exists. */
        declaredBy: string | null;
    } | null;
    urls: { probe: string; approve: string; revoke: string };
    help: HelpContent;
}>;

/** The host, which is the fact being agreed to — and typing it is reading it. */
function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

export default function LegacyLogin({ declaration, urls, help }: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [deciding, setDeciding] = useState(false);

    if (declaration === null) {
        return (
            <>
                <PageHeader
                    help={help}
                    description={consoleT(
                        'console.where_sign_ins_go_for_people_who_have_not_moved_to_cbox_be5f68b3',
                    )}
                />

                <div className="card mt-8">
                    <EmptyState
                        icon="shield"
                        title={consoleT('console.no_app_has_declared_one_10f44f61')}
                        description={consoleT(
                            'console.while_you_are_migrating_off_another_system_your_app_can_a0c796b9',
                        )}
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.where_sign_ins_go_for_people_who_have_not_moved_to_cbox_be5f68b3',
                )}
            />

            <div className="mt-6 space-y-6" style={{ maxWidth: '40rem' }}>
                <Panel
                    title={consoleT('console.declared_endpoint_ed0eefd0')}
                    action={
                        <Pill tone={declaration.approved ? 'success' : 'warning'}>
                            {declaration.approved
                                ? consoleText('console.active_92340695')
                                : consoleText('console.awaiting_approval_ae25c9b1')}
                        </Pill>
                    }
                >
                    <div className="space-y-4">
                        <KvList>
                            <Kv label={consoleT('console.declared_by_4830acab')} prose>
                                {declaration.declaredBy ??
                                    consoleRuntimeText(
                                        'console.an_application_that_no_longer_exists_435d0bd5',
                                    )}
                            </Kv>
                            {/*
                                In full, and readable. An operator cannot judge a URL they
                                cannot see, and this is the one value on the page they are
                                actually being asked about.
                            */}
                            <Kv label={consoleT('console.endpoint_3df9726c')}>{declaration.url}</Kv>
                            {declaration.approved && declaration.approvedAt !== null && (
                                <Kv label={consoleT('console.approved_87b42e40')} prose>
                                    {declaration.approvedAt}
                                </Kv>
                            )}
                        </KvList>

                        {/*
                            The consequence, in the two directions people get wrong. Written
                            here rather than in a tooltip because it is the whole decision.
                        */}
                        <div
                            className="card p-4"
                            style={{
                                borderColor: 'var(--accent-edge)',
                                background: 'var(--accent-soft)',
                            }}
                        >
                            <p className="text-sm font-medium">
                                {consoleT('console.what_approving_does_0f674825')}
                            </p>
                            <ul
                                className="text-xs mt-2 space-y-1"
                                style={{
                                    color: 'var(--muted-foreground)',
                                    listStyle: 'disc outside',
                                    paddingLeft: '1.1rem',
                                }}
                            >
                                <li>
                                    {consoleRich(
                                        'console.someone_who_signs_in_and_is_part1_has_their_email_and_p_07a25128',
                                        {
                                            part1: (
                                                <b>
                                                    {consoleT(
                                                        'console.not_yet_in_cbox_id_e5419fd1',
                                                    )}
                                                </b>
                                            ),
                                        },
                                    )}
                                </li>
                                <li>
                                    {consoleRich(
                                        'console.someone_who_part1_is_never_sent_their_password_is_the_o_6e557319',
                                        {
                                            part1: (
                                                <b>
                                                    {consoleT(
                                                        'console.already_exists_here_4acf85c5',
                                                    )}
                                                </b>
                                            ),
                                        },
                                    )}
                                </li>
                                <li>
                                    {consoleRich(
                                        'console.if_that_endpoint_is_part1_people_who_have_not_migrated_18fc0415',
                                        { part1: <b>{consoleT('console.down_908aec45')}</b> },
                                    )}
                                </li>
                            </ul>
                        </div>
                    </div>
                </Panel>

                {/*
                    The check BEFORE the decision, and above it on purpose: an operator who
                    has not tried the endpoint should meet this first.
                */}
                <Probe href={urls.probe} />

                <Panel
                    title={
                        declaration.approved
                            ? consoleText('console.withdraw_approval_76838799')
                            : consoleText('console.approve_this_endpoint_a21af281')
                    }
                    description={
                        declaration.approved
                            ? consoleText(
                                  'console.anyone_who_has_not_migrated_yet_stops_being_able_to_sig_c4a8bc03',
                              )
                            : consoleText(
                                  'console.from_now_on_the_email_and_password_of_anyone_who_is_not_9f2adece',
                              )
                    }
                >
                    <Button variant="danger" onClick={() => setDeciding(true)}>
                        {declaration.approved
                            ? consoleText('console.withdraw_164546a9')
                            : consoleText('console.approve_this_endpoint_a21af281')}
                    </Button>
                </Panel>

                <p className="text-xs" style={{ color: 'var(--faint)' }}>
                    {consoleT(
                        'console.if_the_app_declares_a_different_url_later_this_approval_d8bd0ea4',
                    )}
                </p>
            </div>

            {/*
                TYPE-TO-CONFIRM, and the typed string is the endpoint's HOST rather than the
                app's name: the host is the fact being agreed to, and typing it is reading
                it. This is not undoable in any meaningful sense — once an endpoint has been
                approved it has been offered live passwords.
            */}
            <ConfirmDelete
                open={deciding}
                onOpenChange={setDeciding}
                name={hostOf(declaration.url)}
                verb={
                    declaration.approved
                        ? consoleShortText('console.withdraw_164546a9')
                        : consoleShortText('console.approve_6007acbe')
                }
                consequence={
                    declaration.approved
                        ? consoleAttributeText(
                              'console.anyone_who_has_not_migrated_yet_stops_being_able_to_sig_ed9f870a',
                          )
                        : consoleAttributeText(
                              'console.from_now_on_the_email_and_password_of_anyone_who_is_not_9f2adece',
                          )
                }
                onConfirm={() => {
                    setDeciding(false);
                    router.post(
                        declaration.approved ? urls.revoke : urls.approve,
                        {},
                        { preserveScroll: true },
                    );
                }}
            />
        </>
    );
}

/**
 * Ask the endpoint whether it is alive, before anybody approves it.
 *
 * IT SENDS NO PASSWORD. The probe asks whether the address is known and nothing more, so
 * this screen cannot become a credential-testing oracle with an approve button beside it.
 */
function Probe({ href }: { href: string }) {
    const { t: consoleT } = useConsoleTranslator();
    const result = usePage().flash.probeResult;
    const form = useForm({ email: '' });

    return (
        <Panel
            title={consoleT('console.try_it_first_07cff7ec')}
            description={consoleT(
                'console.checks_whether_the_endpoint_answers_for_an_address_you_3fb87b94',
            )}
        >
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, { preserveScroll: true });
                }}
            >
                <div className="flex flex-wrap items-end gap-2">
                    <Field
                        label={consoleT('console.an_address_in_your_old_system_cee86536')}
                        hint={consoleT(
                            'console.use_your_own_the_answer_says_whether_an_account_exists_7e30353b',
                        )}
                        className="flex-1"
                        error={form.errors.email}
                    >
                        <Input
                            name="email"
                            type="email"
                            autoComplete="off"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                    </Field>
                    <Button type="submit" className="shrink-0" loading={form.processing}>
                        {consoleT('console.test_endpoint_5983d109')}
                    </Button>
                </div>

                {/* Announced: the answer arrives with no focus change. */}
                <output className="block text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {result}
                </output>
            </form>
        </Panel>
    );
}

LegacyLogin.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
