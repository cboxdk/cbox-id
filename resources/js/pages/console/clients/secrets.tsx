import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

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
                    {tc('console.your_password_is_confirmed_press_the_button__42ad1820')}
                </output>
            )}

            <Panel
                title={tc('console.live_secrets_31a7e6b8')}
                description={tc('console.every_secret_that_signs_this_app_in_right_no_6aceec1d')}
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
                                            ? tc('console.csec_value0_8e4ecb1d', {
                                                  value0: secret.hint,
                                              })
                                            : tc(
                                                  'console.a_secret_from_before_secrets_were_listed_d5e46ffa',
                                              )}
                                    </span>
                                    {secret.expiring ? (
                                        <Pill tone="warning">
                                            {tc('console.replaced_still_working_07f500ce')}
                                        </Pill>
                                    ) : (
                                        <Pill tone="success">{tc('console.current_e0d1b682')}</Pill>
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
                                    {tc('console.revoke_87e6d00b')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
                {secrets.length === 1 && (
                    <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.this_is_the_app_s_only_live_secret_so_it_can_d05986bb')}
                    </p>
                )}
            </Panel>

            <Panel
                title={tc('console.rotate_secret_4405518d')}
                description={tc('console.create_a_new_client_secret_choose_how_long_t_7788f8af')}
            >
                <RadioGroup
                    label={tc('console.the_current_secret_stops_working_197f3bae')}
                    name="grace"
                    value={grace}
                    onValueChange={setGrace}
                    options={graces.map((option) => ({
                        value: String(option.value),
                        label: option.label,
                        hint:
                            option.value === 0
                                ? tc(
                                      'console.for_a_secret_that_has_leaked_anything_still__7b1c0246',
                                  )
                                : undefined,
                    }))}
                />
                <Button className="mt-4" size="sm" onClick={() => setRotating(true)}>
                    {tc('console.rotate_secret_4405518d')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={rotating}
                onOpenChange={setRotating}
                name={appHeader.name}
                verb={tc('console.rotate_secret_4405518d')}
                title={tc('console.rotate_the_secret_for_value0_e529e4a3', {
                    value0: appHeader.name,
                })}
                consequence={
                    immediate
                        ? tc('console.the_current_client_secret_stops_working_imme_030370f0')
                        : `The current client secret keeps working ${chosen.label.toLowerCase().replace('after', 'for')}, then stops. Move every deployment to the new one before then.`
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
                verb="Revoke"
                title={
                    revoking?.hint != null
                        ? tc('console.revoke_the_secret_ending_in_value0_1c80da8d', {
                              value0: revoking.hint,
                          })
                        : tc('console.revoke_this_secret_b7a2f049')
                }
                actionLabel={tc('console.revoke_secret_2d7f529d')}
                consequence={tc('console.it_stops_working_immediately_anything_still__4a9caff7')}
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
