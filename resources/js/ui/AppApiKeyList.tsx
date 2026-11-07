import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Badge } from './Badge';
import { Button } from './Button';
import { ConfirmDelete } from './ConfirmDelete';
import { EmptyState } from './EmptyState';
import { type KeyLifecycle, KeyStatusPill, KeyTimeline } from './KeyLifecycle';

/**
 * One API key a person holds for an app — the server's `AppApiKeyRowProps`.
 *
 * `permissions` is what the key was ISSUED with, its ceiling. What it may do at any moment
 * is that list cut down to what its holder holds then, which the app learns when it
 * verifies the key.
 */
export interface AppApiKey {
    id: string;
    name: string | null;
    prefix: string;
    appName: string;
    permissions: string[];
    /** Null on the holder's own page, where it would only ever say "you". */
    holder: { name: string; email: string | null } | null;
    lifecycle: KeyLifecycle;
    /** Null once the key is no longer active: there is nothing left to stop. */
    revokeHref: string | null;
}

/**
 * The key list, one shape on all three pages that show it — the holder's own page, and the
 * two where an organization's administrators see every key in it — so what a key IS cannot
 * read differently depending on who is looking.
 *
 * Revoking asks for the key's name to be typed, on every page. A key is usually wired into
 * something that stops the moment it is revoked, and the person on the other end of that
 * integration may not be the one clicking.
 */
export function AppApiKeyList({
    keys,
    empty,
    consequence,
}: {
    keys: AppApiKey[];
    empty: { title: string; description: ReactNode };
    consequence: string;
}) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleT } = useConsoleTranslator();
    const [revoking, setRevoking] = useState<AppApiKey | null>(null);

    if (keys.length === 0) {
        return <EmptyState icon="key" title={empty.title} description={empty.description} />;
    }

    return (
        <>
            <ul>
                {keys.map((key, index) => (
                    <li
                        key={key.id}
                        className="flex items-start gap-3 flex-wrap px-4 py-3"
                        style={
                            index < keys.length - 1
                                ? { borderBottom: '1px solid var(--border)' }
                                : undefined
                        }
                    >
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <div className="flex items-center gap-2 flex-wrap">
                                <span className="font-medium break-words min-w-0">
                                    {key.name ??
                                        consoleRuntimeText('console.untitled_key_c8ec47ab')}
                                </span>
                                <KeyStatusPill lifecycle={key.lifecycle} />
                                <Badge>{key.appName}</Badge>
                            </div>

                            {key.holder !== null && (
                                <p className="text-sm break-words">
                                    <span className="sr-only">
                                        {consoleT('console.held_by_c761bca7')}{' '}
                                    </span>
                                    {key.holder.name}
                                    {key.holder.email !== null &&
                                        key.holder.email !== key.holder.name && (
                                            <span style={{ color: 'var(--muted-foreground)' }}>
                                                {' '}
                                                · {key.holder.email}
                                            </span>
                                        )}
                                </p>
                            )}

                            <div className="flex flex-wrap gap-1">
                                {key.permissions.length === 0 ? (
                                    <span
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {consoleT(
                                            'console.no_permissions_it_identifies_its_holder_and_nothing_mor_80cf422c',
                                        )}
                                    </span>
                                ) : (
                                    <>
                                        <span className="sr-only">
                                            {consoleT('console.permissions_79e3c033')}
                                        </span>
                                        {key.permissions.map((permission) => (
                                            <Badge key={permission} className="mono">
                                                {permission}
                                            </Badge>
                                        ))}
                                    </>
                                )}
                            </div>

                            <KeyTimeline lifecycle={key.lifecycle} prefix={key.prefix} />
                        </div>

                        {key.revokeHref !== null && (
                            <Button
                                size="sm"
                                variant="danger"
                                className="shrink-0"
                                aria-label={consoleFinalText('console.revoke_part1_a9e9c495', {
                                    part1: key.name ?? key.prefix,
                                })}
                                onClick={() => setRevoking(key)}
                            >
                                {consoleT('console.revoke_87e6d00b')}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => setRevoking(open ? revoking : null)}
                name={revoking?.name ?? revoking?.prefix ?? ''}
                verb={consoleShortText('console.revoke_87e6d00b')}
                consequence={consequence}
                onConfirm={() => {
                    const key = revoking;
                    setRevoking(null);

                    if (key?.revokeHref) {
                        router.delete(key.revokeHref, { preserveScroll: true });
                    }
                }}
            />
        </>
    );
}
