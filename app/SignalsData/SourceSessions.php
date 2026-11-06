<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** State and the export fact commit together; read/touch never manufactures activity. */
final readonly class SourceSessions implements SessionManager
{
    /** @param Closure(): Request $request */
    public function __construct(private SessionManager $inner, private SourceLedger $ledger, private IdentityCapture $capture, private Closure $request) {}

    public function start(string $userId, ?string $organizationId, array $amr, ?string $ip = null, ?string $userAgent = null): Session
    {
        if ($this->ledger->environment() === null) {
            return $this->inner->start($userId, $organizationId, $amr, $ip, $userAgent);
        }

        return DB::transaction(function () use ($userId, $organizationId, $amr, $ip, $userAgent): Session {
            $session = $this->inner->start($userId, $organizationId, $amr, $ip, $userAgent);
            $this->capture->started($session, ($this->request)());

            return $session;
        });
    }

    public function active(string $sessionId): ?Session
    {
        return $this->inner->active($sessionId);
    }

    public function revoke(string $sessionId): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->revoke($sessionId);

            return;
        }
        DB::transaction(function () use ($sessionId): void {
            $session = Session::query()->whereKey($sessionId)->lockForUpdate()->first();
            $active = $session !== null && $session->revoked_at === null;
            $this->inner->revoke($sessionId);
            if ($active) {
                $request = ($this->request)();
                $this->capture->session($session, $request->attributes->get('signals.source.logout') === true ? 'logout' : 'revoked', $request);
            }
        });
    }

    public function revokeAllForUser(string $userId): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->revokeAllForUser($userId);

            return;
        }
        DB::transaction(function () use ($userId): void {
            $sessions = Session::query()->where('user_id', $userId)->whereNull('revoked_at')->lockForUpdate()->get();
            $this->inner->revokeAllForUser($userId);
            foreach ($sessions as $session) {
                $request = ($this->request)();
                $this->capture->session($session, $request->attributes->get('signals.source.logout') === true ? 'logout' : 'revoked', $request);
            }
        });
    }
}
