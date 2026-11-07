import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Button,
    ConfirmDelete,
    type KeyLifecycle,
    KeyTimeline,
    Panel,
    Pill,
    RadioGroup,
} from '@/ui';
import { AppFrame, type AppHeaderData } from './frame';

/** `App\Http\Props\Console\ClientSecretProps` */
interface SecretRow {
    id: string;
    /** The last four characters. Null for a secret older than per-secret storage. */
    hint: string | null;
    /** Replaced by a rotation, and still working until `lifecycle.expiresAt`. */
    expiring: boolean;
    lifecycle: KeyLifecycle;
    /** Null for the app's only live secret: that one is rotated, not revoked. */
    revokeHref: string | null;
}

type Props = PageProps<{
    appHeader: AppHeaderData;
    secrets: SecretRow[];
    /** `App\Platform\Enums\SecretGrace::offered()` — seconds, and the words for them. */
    graces: { value: number; label: string }[];
    rotateHref: string;
    /** A step-up was just cleared — say so, do not act on it. */
    stepUpCleared: boolean;
}>;

export default function ClientSecrets({
    appHeader,
    secrets,
    graces,
    rotateHref,
    stepUpCleared,
}: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleFinalText, locale } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // An hour by default where the install allows one: long enough to roll a new secret
    // out, short enough that nobody forgets the old one is still alive. A leak is the
    // other case, and "Immediately" is the first choice in the list for it.
    const [grace, setGrace] = useState(
        String(graces.find((option) => option.value === 3600)?.value ?? graces[0]?.value ?? 0),
    );
    const [rotating, setRotating] = useState(false);
    const [revoking, setRevoking] = useState<SecretRow | null>(null);

    const chosen = graces.find((option) => String(option.value) === grace);
    const immediate = chosen === undefined || chosen.value === 0;

    return (
        <AppFrame app={appHeader}>
            {/*
                A STEP-UP THAT HAS JUST BEEN CLEARED. Rotating or revoking sends the
                administrator to re-enter their password and brings them back here — to a
                page that looks exactly as it did, with nothing done and nothing explaining
                why. People concluded it was broken and did the whole thing again.
            */}
            {stepUpCleared && (
                <output
                    className="block rounded-xl border p-4 text-sm"
                    style={{
                        borderColor: 'color-mix(in oklch, var(--accent) 40%, transparent)',
                        background: 'var(--accent-soft)',
                    }}
                >
                    {consoleT(
                        'console.your_password_is_confirmed_press_the_button_again_to_fi_42ad1820',
                    )}
                </output>
            )}

            <Panel
                title={consoleT('console.live_secrets_31a7e6b8')}
                description={consoleT(
                    'console.every_secret_that_signs_this_app_in_right_now_only_a_ha_6aceec1d',
                )}
            >
                <ul className="space-y-3">
                    {secrets.map((secret) => (
                        <li
                            key={secret.id}
                            className="rounded-lg p-3 flex flex-wrap items-start gap-3"
                            style={{ border: '1px solid var(--border)' }}
                        >
                            <div className="min-w-0 flex-1 space-y-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="mono text-sm">
                                        {secret.hint !== null
                                            ? `csec_…${secret.hint}`
                                            : consoleText(
                                                  'console.a_secret_from_before_secrets_were_listed_d5e46ffa',
                                              )}
                                    </span>
                                    {secret.expiring ? (
                                        <Pill tone="warning">
                                            {consoleT('console.replaced_still_working_07f500ce')}
                                        </Pill>
                                    ) : (
                                        <Pill tone="success">
                                            {consoleT('console.current_e0d1b682')}
                                        </Pill>
                                    )}
                                </div>
                                <KeyTimeline lifecycle={secret.lifecycle} />
                            </div>
                            {secret.revokeHref !== null && (
                                <Button
                                    size="sm"
                                    variant="danger"
                                    className="shrink-0"
                                    onClick={() => setRevoking(secret)}
                                >
                                    {consoleT('console.revoke_87e6d00b')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
                {secrets.length === 1 && (
                    <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.this_is_the_app_s_only_live_secret_so_it_cannot_be_revo_d05986bb',
                        )}
                    </p>
                )}
            </Panel>

            <Panel
                title={consoleT('console.rotate_secret_4405518d')}
                description={consoleT(
                    'console.create_a_new_client_secret_choose_how_long_the_current_7788f8af',
                )}
            >
                <RadioGroup
                    label={consoleT('console.the_current_secret_stops_working_197f3bae')}
                    name="grace"
                    value={grace}
                    onValueChange={setGrace}
                    options={graces.map((option) => ({
                        value: String(option.value),
                        label: option.label,
                        hint:
                            option.value === 0
                                ? consoleAttributeText(
                                      'console.for_a_secret_that_has_leaked_anything_still_using_it_fa_7b1c0246',
                                  )
                                : undefined,
                    }))}
                />
                <Button className="mt-4" size="sm" onClick={() => setRotating(true)}>
                    {consoleT('console.rotate_secret_4405518d')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={rotating}
                onOpenChange={setRotating}
                name={appHeader.name}
                verb={consoleAttributeText('console.rotate_secret_4405518d')}
                title={consoleFinalText('console.rotate_the_secret_for_part1_f3981281', {
                    part1: appHeader.name,
                })}
                consequence={
                    immediate
                        ? consoleAttributeText(
                              'console.the_current_client_secret_stops_working_immediately_and_030370f0',
                          )
                        : consoleFinalText('console.secret_rotation_remaining_duration', {
                              duration: new Intl.NumberFormat(locale, {
                                  style: 'unit',
                                  unit: chosen.value >= 86400 ? 'day' : 'hour',
                                  unitDisplay: 'long',
                              }).format(chosen.value / (chosen.value >= 86400 ? 86400 : 3600)),
                          })
                }
                onConfirm={() => {
                    setRotating(false);
                    router.post(rotateHref, { grace: Number(grace) }, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                name={appHeader.name}
                verb={consoleShortText('console.revoke_87e6d00b')}
                title={
                    revoking?.hint != null
                        ? consoleFinalText('console.revoke_the_secret_ending_in_part1_c6b15a80', {
                              part1: revoking.hint,
                          })
                        : consoleText('console.revoke_this_secret_b7a2f049')
                }
                actionLabel={consoleUiText('console.revoke_secret_2d7f529d')}
                consequence={consoleUiText(
                    'console.it_stops_working_immediately_anything_still_using_it_fa_4a9caff7',
                )}
                onConfirm={() => {
                    const href = revoking?.revokeHref;
                    setRevoking(null);

                    if (href != null) {
                        router.delete(href, { preserveScroll: true });
                    }
                }}
            />
        </AppFrame>
    );
}

ClientSecrets.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
