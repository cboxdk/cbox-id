import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

interface State {
    error: Error | null;
}

/**
 * THE LAST THING BETWEEN A RENDER BUG AND A WHITE PAGE.
 *
 * A server-rendered console degrades gracefully by construction: a page that throws
 * returns a 500 the operator can read, and the previous page is still in the browser.
 * A client-rendered one does not — an exception during render unmounts the whole tree,
 * and what the person sees is nothing at all, on a console they may have reached in the
 * middle of an incident.
 *
 * So the boundary is at the root, above the page and above the chrome, and it offers the
 * one thing that always works: leave. A full navigation re-enters through the server,
 * which rebuilds the props from scratch — so a page broken by stale client state fixes
 * itself, and a page broken by a real bug at least says so.
 *
 * It does NOT report the error anywhere. The console runs under a strict CSP with no
 * third-party origins, and adding one for telemetry is a decision about the product's
 * privacy posture, not about error handling.
 */
export class AppErrorBoundary extends Component<Props, State> {
    public override state: State = { error: null };

    public static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    public override componentDidCatch(error: Error, info: ErrorInfo): void {
        // Kept, because the alternative is a silent white page in development too.
        // eslint-disable-next-line no-console
        console.error('Unhandled render error', error, info.componentStack);
    }

    public override render(): ReactNode {
        const { error } = this.state;

        if (error === null) {
            return this.props.children;
        }

        return (
            <main id="main-content" className="auth-shell">
                <div className="card" style={{ maxWidth: '32rem', padding: '2rem' }}>
                    <h1 className="cbx-page-title">Something went wrong</h1>
                    <p className="cbx-page-desc">
                        This page could not be displayed. Reloading it starts over from the
                        server, which resolves most causes.
                    </p>

                    {import.meta.env.DEV && (
                        <pre
                            className="mono"
                            style={{
                                marginTop: '1rem',
                                padding: '0.75rem',
                                overflowX: 'auto',
                                fontSize: '0.75rem',
                                background: 'var(--muted-color)',
                                borderRadius: 'var(--radius-md)',
                            }}
                        >
                            {error.message}
                        </pre>
                    )}

                    <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.5rem' }}>
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => window.location.reload()}
                        >
                            Reload this page
                        </button>
                        <a className="btn btn-secondary" href="/">
                            Go to the dashboard
                        </a>
                    </div>
                </div>
            </main>
        );
    }
}
