<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Http\Middleware\AuthenticateMcp;
use App\Platform\Actions\AppManagementScopes;
use App\Platform\Actions\Principal\AppTokenPrincipal;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Actions\Principal\SignedInPerson;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/**
 * The credential authenticated for the current request on ONE environment's management
 * plane: an environment API key — the machine equivalent of an admin's session — or a
 * person's own access token, signed in through an agent or the CLI. The environment itself
 * is already resolved from the request host (ResolveEnvironment), so this only carries the
 * credential. Bound per-request (scoped) and populated by {@see AuthenticateEnvironmentApi}
 * and {@see AuthenticateMcp}.
 *
 * On a route whose scope an app's own token may carry
 * ({@see AppManagementScopes::machine()}), the credential can
 * instead be that app's client-credentials token ({@see AppTokenPrincipal}).
 *
 * At most one of them is set. {@see key()} stays the KEY alone, because the routes that
 * read it — the ones not yet actions — act as a key and nothing else; {@see Principal()} is
 * the question the action layer asks.
 *
 * The person is any {@see SignedInPerson}: the environment's own subject
 * ({@see DelegatedTokenPrincipal}), or on the root host a workspace member — unbound
 * ({@see RootPersonPrincipal}) for the approval poll, bound to the environment named for the
 * call ({@see EnvironmentMemberPrincipal}) for an action.
 */
final class EnvironmentApiContext
{
    private ?EnvironmentApiKey $key = null;

    private ?SignedInPerson $delegated = null;

    private ?AppTokenPrincipal $app = null;

    public function set(EnvironmentApiKey $key): void
    {
        $this->key = $key;
        $this->delegated = null;
        $this->app = null;
    }

    public function setDelegated(SignedInPerson $principal): void
    {
        $this->delegated = $principal;
        $this->key = null;
        $this->app = null;
    }

    /** An app's own client-credentials token, admitted on a route whose scope it may carry. */
    public function setApp(AppTokenPrincipal $principal): void
    {
        $this->app = $principal;
        $this->key = null;
        $this->delegated = null;
    }

    /**
     * Forget the key once its request is answered.
     *
     * A `scoped` binding is only reset between requests under Octane. Everywhere else — a
     * queue worker, the test suite, a console command running after an in-process request —
     * the key would stay "authenticated" for whatever ran next, and
     * {@see EnvironmentKeyAuditLog} would attribute that work to it. The same for a person's
     * token.
     */
    public function clear(): void
    {
        $this->key = null;
        $this->delegated = null;
        $this->app = null;
    }

    public function key(): ?EnvironmentApiKey
    {
        return $this->key;
    }

    /** The person whose access token authenticated this request, when one did. */
    public function delegated(): ?SignedInPerson
    {
        return $this->delegated;
    }

    /** Whoever this request acts as, in the action layer's terms. */
    public function principal(): ?Principal
    {
        if ($this->key !== null) {
            return new EnvironmentKeyPrincipal($this->key);
        }

        return $this->delegated ?? $this->app;
    }

    /** The environment the authenticated credential belongs to (host-resolved and credential-bound agree). */
    public function environmentId(): ?string
    {
        if ($this->key !== null) {
            return $this->key->environment_id;
        }

        return $this->delegated instanceof DelegatedTokenPrincipal || $this->delegated instanceof EnvironmentMemberPrincipal
            ? $this->delegated->environmentId()
            : null;
    }
}
