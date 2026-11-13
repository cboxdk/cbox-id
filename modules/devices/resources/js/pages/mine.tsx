import { useTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountLayout from '@/layouts/AccountLayout';
import type { HelpContent, PageProps } from '@/types';
import { SettingRow } from '@/chrome/AccountRows';
import { relativeTime } from '@/lib/time';
import { Button, Dialog, EmptyState, PageHeader, Panel, Pill } from '@/ui';

interface DeviceRow {
    id: string;
    name: string;
    platform: string;
    status: string;
    active: boolean;
    /** ISO 8601; drawn in the reader's language. */
    lastSeen: string | null;
    removeHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    enrolment: { uri: string; qr: string; refreshSeconds: number } | null;
    /** Set while the code is held back for a fresh "Confirm it's you"; null once shown. */
    unlockHref: string | null;
    appStoreUrl: string | null;
    devices: DeviceRow[];
}>;

export default function MyDevices({ help, enrolment, unlockHref, appStoreUrl, devices }: Props) {
    const { t: tc, locale } = useTranslator();

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
                description={tc('console.phones_enrolled_in_the_authenticator_app_the_c53bb961')}
            />

            {unlockHref !== null ? (
                /*
                    THE CODE WAITS FOR "CONFIRM IT'S YOU", like adding a passkey: it is a
                    way to add an approval device. The button's request is refused with the
                    step-up the page answers in place, then replayed, and the reload that
                    follows shows the code.
                */
                <Panel title={tc('console.add_a_phone_f2ec926d')}>
                    <div className="space-y-3">
                        <p className="text-sm" style={{ color: 'var(--muted)' }}>
                            {tc('console.confirm_it_s_you_to_show_the_code_that_conne_3b6e0c41')}
                        </p>
                        <Button
                            onClick={() => router.post(unlockHref, {}, { preserveScroll: true })}
                        >
                            {tc('console.show_the_code_9a4f2d17')}
                        </Button>
                    </div>
                </Panel>
            ) : enrolment === null ? (
                /* Reached only when self-provisioning failed — the error is in the logs. */
                <Panel title={tc('console.add_a_phone_f2ec926d')}>
                    <p className="text-sm" style={{ color: 'var(--muted)' }}>
                        {tc('console.setting_up_the_authenticator_app_for_this_en_90ff9348')}
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
                                alt={tc('console.enrolment_qr_code_815a6720')}
                                width={220}
                                height={220}
                            />
                        </div>

                        <div className="min-w-0 space-y-3">
                            <h2 className="text-base font-medium">
                                {tc('console.add_a_phone_f2ec926d')}
                            </h2>

                            {/*
                                The instructions differ by which device you are on, so they
                                are written twice rather than hedged into one sentence that
                                is half-wrong everywhere.
                            */}
                            <p className="text-sm sm:hidden" style={{ color: 'var(--muted)' }}>
                                {tc('console.open_b8039d1d')}
                                <strong>{tc('console.cbox_id_ce17c4bb')}</strong>
                                {tc('console.on_this_phone_to_finish_enrolling_it_7ee957d6')}
                            </p>
                            <p
                                className="hidden sm:block text-sm"
                                style={{ color: 'var(--muted)' }}
                            >
                                {tc('console.install_240466e4')}
                                <strong>{tc('console.cbox_id_ce17c4bb')}</strong>
                                {tc(
                                    'console.on_your_phone_open_it_and_scan_this_code_you_a544ca67',
                                )}
                            </p>

                            {/*
                                THE DEEP LINK, as a tap target rather than as printed text —
                                and a plain <a>, never an Inertia <Link>: it is a custom
                                scheme handed to the OS, not a page this app can fetch.
                            */}
                            <div className="flex flex-wrap items-center gap-3 pt-1">
                                <a href={enrolment.uri} className="btn btn-primary sm:hidden">
                                    {tc('console.open_the_cbox_id_app_772f3270')}
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
                                    {tc(
                                        'console.reading_this_on_the_phone_itself_open_the_ap_4b487e64',
                                    )}
                                </a>
                                {appStoreUrl !== null && (
                                    <a
                                        href={appStoreUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-sm underline"
                                        style={{ color: 'var(--muted)' }}
                                    >
                                        {tc('console.get_the_app_ec196e05')}
                                    </a>
                                )}
                            </div>

                            {/*
                                Said plainly, because a code on a screen invites the question
                                — and because the previous wording ("safe to share") stopped
                                being true the moment the code started carrying an identity.
                            */}
                            <p className="text-sm" style={{ color: 'var(--muted)' }}>
                                {tc(
                                    'console.this_link_expires_after_two_minutes_and_only_2009db22',
                                )}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            <Panel title={tc('console.trusted_devices_7d989273')} flush>
                {devices.length === 0 ? (
                    <EmptyState
                        icon="phone"
                        title={tc('console.no_devices_enrolled_yet_use_the_card_above_t_b9a12da2')}
                    />
                ) : (
                    <ul className="acct-list">
                        {devices.map((device) => (
                            <SettingRow
                                key={device.id}
                                icon="phone"
                                title={device.name}
                                badge={
                                    <Pill tone={device.active ? 'success' : 'warning'}>
                                        {device.status}
                                    </Pill>
                                }
                                meta={[
                                    device.platform,
                                    device.lastSeen === null
                                        ? null
                                        : `${tc('console.last_seen_21fd79c7')} ${relativeTime(device.lastSeen, locale)}`,
                                ]
                                    .filter((part) => part !== null)
                                    .join(' · ')}
                                actions={
                                    <Button
                                        size="sm"
                                        aria-label={tc('console.remove_value0_b597e5fb', {
                                            value0: device.name,
                                        })}
                                        onClick={() => setRemoving(device)}
                                    >
                                        {tc('console.remove_c3812fc4')}
                                    </Button>
                                }
                            />
                        ))}
                    </ul>
                )}
            </Panel>

            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={
                    removing === null
                        ? ''
                        : tc('console.remove_value0_b8b38a7a', { value0: removing.name })
                }
                description={tc('console.it_stops_receiving_approval_prompts_and_aler_5c22711e')}
                footer={
                    <>
                        <Button onClick={() => setRemoving(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
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
                            {tc('console.remove_c3812fc4')}
                        </Button>
                    </>
                }
            />
        </div>
    );
}

MyDevices.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;
