import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslator } from '@/i18n';
import { Button } from '@/ui';

type State = {
    available?: boolean;
    status: string;
    code?: string;
    expiresAt?: string;
    redirect?: string;
    /** The Universal Link that opens the Authenticator on this request; phones only. */
    appLink?: string | null;
};

/** The phone answers the existing approval; only this browser can spend it. */
export function MobileApproval({
    endpoint,
    onConfirmed,
}: {
    endpoint: string;
    onConfirmed: (redirect: string) => void;
}) {
    const { t } = useTranslator();
    const [state, setState] = useState<State>({ status: 'idle' });
    const [error, setError] = useState(false);
    const [busy, setBusy] = useState(false);
    const done = useRef(onConfirmed);
    useEffect(() => {
        done.current = onConfirmed;
    }, [onConfirmed]);
    const alive = useRef(true);
    const pendingCode = useRef<string | undefined>(undefined);
    useEffect(() => {
        pendingCode.current =
            state.status === 'pending' || state.status === 'approved' ? state.code : undefined;
    }, [state.status, state.code]);

    const call = useCallback(
        async (operation?: string, signal?: AbortSignal, code?: string): Promise<State> => {
            const response = await fetch(endpoint, {
                method: operation ? 'POST' : 'GET',
                credentials: 'same-origin',
                signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') ?? '',
                },
                body: operation
                    ? JSON.stringify({ operation, ...(code ? { code } : {}) })
                    : undefined,
            });
            if (!response.ok) throw new Error('Approval unavailable');
            return (await response.json()) as State;
        },
        [endpoint],
    );

    useEffect(() => {
        alive.current = true;
        const abort = new AbortController();
        void call(undefined, abort.signal)
            .then((s) => {
                if (alive.current) setState(s);
            })
            .catch(() => {});
        return () => {
            alive.current = false;
            abort.abort();
            if (pendingCode.current)
                void call('cancel', undefined, pendingCode.current).catch(() => {});
        };
    }, [call]);

    useEffect(() => {
        if (state.status !== 'pending' && state.status !== 'approved') return;
        const abort = new AbortController();
        const tick = async () => {
            try {
                if (state.expiresAt && Date.parse(state.expiresAt) <= Date.now()) {
                    setState((s) => ({ ...s, status: 'expired' }));
                    return;
                }
                const next = await call(undefined, abort.signal);
                if (next.status === 'approved') {
                    const result = await call('complete', abort.signal);
                    if (result.status !== 'confirmed' || !result.redirect)
                        throw new Error('Approval not confirmed');
                    if (alive.current && !abort.signal.aborted) {
                        setState(result);
                        done.current(result.redirect);
                    }
                } else if (alive.current && !abort.signal.aborted) setState(next);
            } catch {
                if (!abort.signal.aborted && alive.current) {
                    setError(true);
                    setState((s) => ({ ...s, status: 'failed' }));
                }
            }
        };
        let timer = window.setTimeout(() => void tick(), 5000);
        // Back from the Authenticator: look now rather than up to five seconds later, so
        // the sign-in is already moving when the person lands on the page.
        const onVisible = () => {
            if (document.visibilityState !== 'visible') return;
            window.clearTimeout(timer);
            timer = window.setTimeout(() => void tick(), 0);
        };
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
            abort.abort();
        };
    }, [state, call]);

    async function act(operation: 'start' | 'cancel') {
        setBusy(true);
        setError(false);
        try {
            const next = await call(
                operation,
                undefined,
                operation === 'cancel' ? pendingCode.current : undefined,
            );
            if (alive.current)
                setState(operation === 'cancel' ? { available: true, status: 'idle' } : next);
        } catch {
            if (alive.current) setError(true);
        } finally {
            if (alive.current) setBusy(false);
        }
    }

    if (!state.available && state.status !== 'confirmed') return null;
    const waiting = state.status === 'pending' || state.status === 'approved';
    return (
        <div className="mt-5 space-y-3 border-t pt-4" style={{ borderColor: 'var(--border)' }}>
            {waiting ? (
                <>
                    <p className="text-sm">{t('console.mobile_approval_compare')}</p>
                    <output
                        className="block text-center text-2xl font-mono tracking-widest"
                        aria-live="polite"
                    >
                        {state.code}
                    </output>
                    {state.appLink && state.status === 'pending' && (
                        <>
                            {/* A Universal Link, not a scheme probe: the system opens the
                                app when it is installed and this page when it is not. */}
                            <Button
                                asChild
                                variant="primary"
                                size="lg"
                                icon="phone"
                                className="w-full"
                            >
                                <a href={state.appLink}>{t('console.mobile_approval_open_app')}</a>
                            </Button>
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                {t('console.mobile_approval_open_app_hint')}
                            </p>
                        </>
                    )}
                    <output className="block text-sm">{t('console.waiting_6e293a8c')}</output>
                    <Button onClick={() => void act('cancel')} loading={busy}>
                        {t('console.cancel_19766ed6')}
                    </Button>
                </>
            ) : (
                <Button className="w-full" onClick={() => void act('start')} loading={busy}>
                    {t('console.mobile_approval_start')}
                </Button>
            )}
            {state.status === 'denied' && <p role="alert">{t('console.denied_da404deb')}</p>}
            {state.status === 'expired' && (
                <p role="alert">
                    {t('console.that_request_is_no_longer_waiting_it_was_answered_used_80728be7')}
                </p>
            )}
            {error && <p role="alert">{t('console.that_did_not_work_try_again_d38eba7d')}</p>}
        </div>
    );
}
