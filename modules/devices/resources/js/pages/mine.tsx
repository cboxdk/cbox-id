import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, Dialog, PageHeader, Panel, Pill, Table, Td, Th } from '@/ui';

interface DeviceRow {
    id: string;
    name: string;
    platform: string;
    status: string;
    active: boolean;
    lastSeen: string | null;
    removeHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    enrolment: { uri: string; qr: string; refreshSeconds: number } | null;
    appStoreUrl: string | null;
    devices: DeviceRow[];
}>;

export default function MyDevices({ help, enrolment, appStoreUrl, devices }: Props) {
    const [removing, setRemoving] = useState<DeviceRow | null>(null);

    /*
        RE-FETCHED INSIDE THE CODE'S OWN LIFETIME, so nobody ever scans one that lapsed
        while they were unlocking their phone. A partial reload of this prop alone: the
        server mints a fresh code and redraws the QR, and nothing else on the page moves.
    */
    useEffect(() => {
        if (enrolment === null) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['enrolment'] }),
            enrolment.refreshSeconds * 1000,
        );

        return () => clearInterval(timer);
    }, [enrolment]);

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description="Phones enrolled in the authenticator app. They receive approval prompts and alerts when your account signs in somewhere new."
            />

            {enrolment === null ? (
                /* Reached only when self-provisioning failed — the error is in the logs. */
                <Panel title="Add a phone">
                    <p className="text-sm" style={{ color: 'var(--muted)' }}>
                        Setting up the authenticator app for this environment didn&rsquo;t succeed.
                        The error has been logged — reload this page to try again.
                    </p>
                </Panel>
            ) : (
                <div className="card p-6">
                    {/*
                        Grid, not flex-wrap. `flex-1` is `flex-basis: 0`, so the text column
                        never reports that it does not fit — it just shrinks, and `flex-wrap`
                        has nothing to react to. On a phone that squeezed the instructions
                        into a column about ten characters wide, one word per line, beside a
                        QR code that kept its full size.
                    */}
                    <div className="grid gap-6 sm:grid-cols-[auto_minmax(0,1fr)] items-start">
                        {/*
                            HIDDEN ON A PHONE, because you cannot scan the screen you are
                            holding. The card used to render the QR at every width and offer
                            nothing else, so opening this page on the device being enrolled —
                            the single most natural thing to do — reached a dead end.
                        */}
                        <div className="hidden sm:block shrink-0 max-w-full">
                            <img
                                src={enrolment.qr}
                                alt="Enrolment QR code"
                                width={220}
                                height={220}
                            />
                        </div>

                        <div className="min-w-0 space-y-3">
                            <h2 className="text-base font-medium">Add a phone</h2>

                            {/*
                                The instructions differ by which device you are on, so they
                                are written twice rather than hedged into one sentence that
                                is half-wrong everywhere.
                            */}
                            <p className="text-sm sm:hidden" style={{ color: 'var(--muted)' }}>
                                Open <strong>Cbox ID</strong> on this phone to finish enrolling
                                it.
                            </p>
                            <p
                                className="hidden sm:block text-sm"
                                style={{ color: 'var(--muted)' }}
                            >
                                Install <strong>Cbox ID</strong> on your phone, open it, and scan
                                this code. You&rsquo;ll then sign in with your normal account in
                                the browser.
                            </p>

                            {/*
                                THE DEEP LINK, as a tap target rather than as printed text —
                                and a plain <a>, never an Inertia <Link>: it is a custom
                                scheme handed to the OS, not a page this app can fetch.
                            */}
                            <div className="flex flex-wrap items-center gap-3 pt-1">
                                <a href={enrolment.uri} className="btn btn-primary sm:hidden">
                                    Open the Cbox ID app
                                </a>
                                {/*
                                    `sm:block`, NOT `sm:inline`. In the compiled stylesheet
                                    `.hidden` lands after the `sm:inline` rule at equal
                                    specificity, so `display:none` wins and the link is in
                                    the DOM at every width and drawn at none of them —
                                    invisible in a way no assertion about the markup can see.
                                */}
                                <a
                                    href={enrolment.uri}
                                    className="hidden sm:block text-sm underline"
                                    style={{ color: 'var(--muted)' }}
                                >
                                    Reading this on the phone itself? Open the app
                                </a>
                                {appStoreUrl !== null && (
                                    <a
                                        href={appStoreUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-sm underline"
                                        style={{ color: 'var(--muted)' }}
                                    >
                                        Get the app
                                    </a>
                                )}
                            </div>

                            {/*
                                Said plainly, because a code on a screen invites the question
                                — and because the previous wording ("safe to share") stopped
                                being true the moment the code started carrying an identity.
                            */}
                            <p className="text-sm" style={{ color: 'var(--muted)' }}>
                                This link expires after two minutes and only works once, for your
                                account. It refreshes on its own while this page is open —
                                don&rsquo;t share a screenshot of it.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            <div className="card">
                <Table caption="The phones enrolled in the authenticator app for your account.">
                    <thead>
                        <tr>
                            <Th>Device</Th>
                            <Th>Platform</Th>
                            <Th>Status</Th>
                            <Th>Last seen</Th>
                            <Th>
                                <span className="sr-only">Actions</span>
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {devices.length === 0 ? (
                            <tr>
                                <Td
                                    colSpan={5}
                                    className="py-10 text-center"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    No devices enrolled yet — use the card above to add your phone.
                                </Td>
                            </tr>
                        ) : (
                            devices.map((device) => (
                                <tr key={device.id}>
                                    <Td className="font-medium">{device.name}</Td>
                                    <Td style={{ color: 'var(--muted)' }}>{device.platform}</Td>
                                    <Td>
                                        <Pill tone={device.active ? 'success' : 'warning'}>
                                            {device.status}
                                        </Pill>
                                    </Td>
                                    <Td
                                        className="whitespace-nowrap mono"
                                        style={{ color: 'var(--muted)' }}
                                    >
                                        {device.lastSeen ?? '—'}
                                    </Td>
                                    <Td className="text-right">
                                        <Button
                                            size="sm"
                                            variant="danger"
                                            aria-label={`Remove ${device.name}`}
                                            onClick={() => setRemoving(device)}
                                        >
                                            Remove
                                        </Button>
                                    </Td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </div>

            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={removing === null ? '' : `Remove ${removing.name}?`}
                description="It stops receiving approval prompts and alerts. You can enrol it again from this page."
                footer={
                    <>
                        <Button onClick={() => setRemoving(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const device = removing;
                                setRemoving(null);

                                if (device !== null) {
                                    router.delete(device.removeHref, { preserveScroll: true });
                                }
                            }}
                        >
                            Remove
                        </Button>
                    </>
                }
            />
        </div>
    );
}

MyDevices.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
