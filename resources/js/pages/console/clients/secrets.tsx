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
                    Your password is confirmed. Press the button again to finish.
                </output>
            )}

            <Panel
                title="Live secrets"
                description="Every secret that signs this app in right now. Only a hash of each is stored — the list shows the last four characters so you can tell which one a deployment holds."
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
                                            : 'A secret from before secrets were listed'}
                                    </span>
                                    {secret.expiring ? (
                                        <Pill tone="warning">Replaced — still working</Pill>
                                    ) : (
                                        <Pill tone="success">Current</Pill>
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
                                    Revoke
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
                {secrets.length === 1 && (
                    <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        This is the app's only live secret, so it cannot be revoked on its own —
                        that would switch the app off with nothing in its place. Rotate it instead.
                    </p>
                )}
            </Panel>

            <Panel
                title="Rotate secret"
                description="Create a new client secret. Choose how long the current one keeps working, so every deployment can move to the new one before the old one stops."
            >
                <RadioGroup
                    label="The current secret stops working"
                    name="grace"
                    value={grace}
                    onValueChange={setGrace}
                    options={graces.map((option) => ({
                        value: String(option.value),
                        label: option.label,
                        hint:
                            option.value === 0
                                ? 'For a secret that has leaked. Anything still using it fails at once.'
                                : undefined,
                    }))}
                />
                <Button className="mt-4" size="sm" onClick={() => setRotating(true)}>
                    Rotate secret
                </Button>
            </Panel>

            <ConfirmDelete
                open={rotating}
                onOpenChange={setRotating}
                name={appHeader.name}
                verb="Rotate secret"
                title={`Rotate the secret for ${appHeader.name}?`}
                consequence={
                    immediate
                        ? 'The current client secret stops working immediately and cannot be recovered — every deployment still holding it starts failing authentication.'
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
                        ? `Revoke the secret ending in ${revoking.hint}?`
                        : 'Revoke this secret?'
                }
                actionLabel="Revoke secret"
                consequence="It stops working immediately. Anything still using it fails to sign in."
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
