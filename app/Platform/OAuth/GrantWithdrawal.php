<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\SignalsData\IdentityCapture;
use App\SignalsData\SourceLedger;
use App\SignalsData\SourceRefreshTokens;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws the OAuth grants that descend from particular sign-ins, and only those.
 *
 * {@see RefreshTokens::withdrawAccess()} is the verb for "this person's access is over"
 * and ends every grant they hold. Two decisions are narrower than that and used to end
 * nothing at all:
 *
 *  - ending some of a person's SESSIONS (signing out every other session, changing the
 *    password): the apps those sessions signed in to were told by back-channel logout,
 *    and the refresh tokens they held went on minting access tokens;
 *  - an SSO mandate making a way of signing in illegitimate: every session was ended,
 *    and every grant a password sign-in produced kept refreshing.
 *
 * The grants of the session the person is using, and grants from a sign-in that is still
 * legitimate (the Authenticator signed in through the mandated identity provider, an app
 * in this browser), are kept. That is the whole difference from `withdrawAccess()`.
 *
 * The access tokens those grants' apps hold are revoked with them: a refresh token
 * withdrawn and an access token left valid is a renewal through token exchange. An
 * access token records no session, so it goes by its (person, app, organization): an app
 * that ALSO holds a kept grant loses only its current access token and refreshes.
 *
 * The session revocation that accompanies each of these already sends the back-channel
 * logout, so nothing is sent from here.
 */
final readonly class GrantWithdrawal
{
    /**
     * The live grants of $userId issued from one of $sessionIds.
     *
     * @param  list<string>  $sessionIds
     */
    public function fromSessions(string $userId, array $sessionIds): int
    {
        if ($sessionIds === []) {
            return 0;
        }

        return $this->withdraw(RefreshToken::query()->where('user_id', $userId)->whereIn('session_id', $sessionIds));
    }

    /**
     * The live grants of $userId whose sign-in was not single sign-on: what an SSO mandate
     * turns into a way in the organization no longer accepts.
     *
     * A family records the `amr` of the login it descends from; a mandated identity
     * provider's sign-in is recorded as `sso`. A family with no recorded `amr` (one issued
     * before the column existed) cannot show it was SSO and goes with the rest.
     */
    public function fromLocalSignIns(string $userId): int
    {
        $ids = RefreshToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->get(['id', 'amr'])
            ->reject(static fn (RefreshToken $token): bool => in_array('sso', array_values($token->amr ?? []), true))
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return $this->withdraw(RefreshToken::query()->whereKey($ids));
    }

    /** @param  Builder<RefreshToken>  $grants */
    private function withdraw(Builder $grants): int
    {
        return DB::transaction(function () use ($grants): int {
            $live = $grants->whereNull('revoked_at')->lockForUpdate()->get();

            if ($live->isEmpty()) {
                return 0;
            }

            $this->record($live);

            RefreshToken::query()->whereKey($live->modelKeys())->update(['revoked_at' => now()]);

            foreach ($live->unique(static fn (RefreshToken $token): string => $token->client_id.'|'.$token->organization_id) as $token) {
                AccessToken::query()
                    ->where('user_id', $token->user_id)
                    ->where('client_id', $token->client_id)
                    ->when(
                        $token->organization_id === null,
                        static fn (Builder $query): Builder => $query->whereNull('organization_id'),
                        static fn (Builder $query): Builder => $query->where('organization_id', $token->organization_id),
                    )
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }

            return $live->count();
        });
    }

    /**
     * Into the signals ledger, as {@see SourceRefreshTokens} records every
     * other revocation, when the environment keeps one.
     *
     * @param  iterable<RefreshToken>  $tokens
     */
    private function record(iterable $tokens): void
    {
        if (app(SourceLedger::class)->environment() === null) {
            return;
        }

        $capture = app(IdentityCapture::class);
        $request = app(Request::class);

        foreach ($tokens as $token) {
            $capture->token($token, 'revoked', $request);
        }
    }
}
