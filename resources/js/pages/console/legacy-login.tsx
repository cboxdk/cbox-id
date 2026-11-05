import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

    const [deciding, setDeciding] = useState(false);

    if (declaration === null) {
        return (
            <>
                <PageHeader
                    help={help}
                    description={tc(
                        'console.where_sign_ins_go_for_people_who_have_not_mo_be5f68b3',
                    )}
                />

                <div className="card mt-8">
                    <EmptyState
                        icon="shield"
                        title={tc('console.no_app_has_declared_one_10f44f61')}
                        description={tc(
                            'console.while_you_are_migrating_off_another_system_y_a0c796b9',
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
                description={tc('console.where_sign_ins_go_for_people_who_have_not_mo_be5f68b3')}
            />

            <div className="mt-6 space-y-6" style={{ maxWidth: '40rem' }}>
                <Panel
                    title={tc('console.declared_endpoint_ed0eefd0')}
                    action={
                        <Pill tone={declaration.approved ? 'success' : 'warning'}>
                            {declaration.approved
                                ? tc('console.active_92340695')
                                : tc('console.awaiting_approval_ae25c9b1')}
                        </Pill>
                    }
                >
                    <div className="space-y-4">
                        <KvList>
                            <Kv label={tc('console.declared_by_4830acab')} prose>
                                {declaration.declaredBy ??
                                    tc('console.an_application_that_no_longer_exists_435d0bd5')}
                            </Kv>
                            {/*
                                In full, and readable. An operator cannot judge a URL they
                                cannot see, and this is the one value on the page they are
                                actually being asked about.
                            */}
                            <Kv label={tc('console.endpoint_3df9726c')}>{declaration.url}</Kv>
                            {declaration.approved && declaration.approvedAt !== null && (
                                <Kv label={tc('console.approved_87b42e40')} prose>
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
                                {tc('console.what_approving_does_0f674825')}
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
                                    {tc('console.someone_who_signs_in_and_is_25793bb2')}
                                    <b>{tc('console.not_yet_in_cbox_id_e5419fd1')}</b>
                                    {tc(
                                        'console.has_their_email_and_password_sent_to_that_en_554a7e63',
                                    )}
                                </li>
                                <li>
                                    {tc('console.someone_who_7ffc7d42')}
                                    <b>{tc('console.already_exists_here_4acf85c5')}</b>
                                    {tc(
                                        'console.is_never_sent_their_password_is_the_one_in_c_208849db',
                                    )}
                                </li>
                                <li>
                                    {tc('console.if_that_endpoint_is_5e5d043f')}
                                    <b>{tc('console.down_908aec45')}</b>
                                    {tc(
                                        'console.people_who_have_not_migrated_cannot_sign_in__8969876e',
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
                            ? tc('console.withdraw_approval_76838799')
                            : tc('console.approve_this_endpoint_a21af281')
                    }
                    description={
                        declaration.approved
                            ? tc('console.anyone_who_has_not_migrated_yet_stops_being__c4a8bc03')
                            : tc('console.from_now_on_the_email_and_password_of_anyone_9f2adece')
                    }
                >
                    <Button variant="danger" onClick={() => setDeciding(true)}>
                        {declaration.approved
                            ? tc('console.withdraw_164546a9')
                            : tc('console.approve_this_endpoint_a21af281')}
                    </Button>
                </Panel>

                <p className="text-xs" style={{ color: 'var(--faint)' }}>
                    {tc('console.if_the_app_declares_a_different_url_later_th_d8bd0ea4')}
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
                verb={declaration.approved ? 'Withdraw' : 'Approve'}
                consequence={
                    declaration.approved
                        ? tc('console.anyone_who_has_not_migrated_yet_stops_being__ed9f870a')
                        : tc('console.from_now_on_the_email_and_password_of_anyone_9f2adece')
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
    const { t: tc } = useTranslator();

    const result = usePage().flash.probeResult;
    const form = useForm({ email: '' });

    return (
        <Panel
            title={tc('console.try_it_first_07cff7ec')}
            description={tc('console.checks_whether_the_endpoint_answers_for_an_a_3fb87b94')}
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
                        label={tc('console.an_address_in_your_old_system_cee86536')}
                        hint={tc('console.use_your_own_the_answer_says_whether_an_acco_7e30353b')}
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
                        {tc('console.test_endpoint_5983d109')}
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
