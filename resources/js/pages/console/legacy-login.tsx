import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
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
}>;

/** The host, which is the fact being agreed to — and typing it is reading it. */
function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

export default function LegacyLogin({ declaration, urls }: Props) {
    const [deciding, setDeciding] = useState(false);

    if (declaration === null) {
        return (
            <>
                <PageHeader description="Where sign-ins go for people who have not moved to Cbox ID yet." />

                <div className="card mt-8">
                    <EmptyState
                        icon="shield"
                        title="No app has declared one"
                        description="While you are migrating off another system, your app can declare where its old login lives — in its manifest, beside its roles. It appears here for you to approve before it does anything."
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <PageHeader description="Where sign-ins go for people who have not moved to Cbox ID yet." />

            <div className="mt-6 space-y-6" style={{ maxWidth: '40rem' }}>
                <Panel
                    title="Declared endpoint"
                    action={
                        <Pill tone={declaration.approved ? 'success' : 'warning'}>
                            {declaration.approved ? 'Active' : 'Awaiting approval'}
                        </Pill>
                    }
                >
                    <div className="space-y-4">
                        <KvList>
                            <Kv label="Declared by" prose>
                                {declaration.declaredBy ?? 'an application that no longer exists'}
                            </Kv>
                            {/*
                                In full, and readable. An operator cannot judge a URL they
                                cannot see, and this is the one value on the page they are
                                actually being asked about.
                            */}
                            <Kv label="Endpoint">{declaration.url}</Kv>
                            {declaration.approved && declaration.approvedAt !== null && (
                                <Kv label="Approved" prose>
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
                            <p className="text-sm font-medium">What approving does</p>
                            <ul
                                className="text-xs mt-2 space-y-1"
                                style={{
                                    color: 'var(--muted-foreground)',
                                    listStyle: 'disc outside',
                                    paddingLeft: '1.1rem',
                                }}
                            >
                                <li>
                                    Someone who signs in and is <b>not yet in Cbox ID</b> has their
                                    email and password sent to that endpoint. If it says yes, they
                                    are created here and never sent again.
                                </li>
                                <li>
                                    Someone who <b>already exists here</b> is never sent — their
                                    password is the one in Cbox ID, and the old system gets no say
                                    in it.
                                </li>
                                <li>
                                    If that endpoint is <b>down</b>, people who have not migrated
                                    cannot sign in. Everyone already moved is unaffected.
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
                    title={declaration.approved ? 'Withdraw approval' : 'Approve this endpoint'}
                    description={
                        declaration.approved
                            ? 'Anyone who has not migrated yet stops being able to sign in until this is approved again.'
                            : 'From now on, the email and password of anyone who is not in Cbox ID yet are sent to that endpoint.'
                    }
                >
                    <Button
                        variant="danger"
                        onClick={() => setDeciding(true)}
                    >
                        {declaration.approved ? 'Withdraw' : 'Approve this endpoint'}
                    </Button>
                </Panel>

                <p className="text-xs" style={{ color: 'var(--faint)' }}>
                    If the app declares a different URL later, this approval is dropped and you will
                    be asked again — a change to where passwords go is not something to inherit
                    silently. Delegated sign-in is a ramp: when few enough people are left on the
                    old system, withdraw it and send that group a password reset.
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
                        ? 'Anyone who has not migrated yet stops being able to sign in until this is approved again. People already moved are unaffected.'
                        : 'From now on, the email and password of anyone who is not in Cbox ID yet are sent to that endpoint.'
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
    const result = usePage().flash.probeResult;
    const form = useForm({ email: '' });

    return (
        <Panel
            title="Try it first"
            description="Checks whether the endpoint answers for an address you know exists over there. No password is sent."
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
                        label="An address in your old system"
                        hint="Use your own — the answer says whether an account exists over there."
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
                        Test endpoint
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
