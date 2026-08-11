<?php

declare(strict_types=1);

namespace App\Support;

use Cbox\Id\OAuthServer\Models\Client;

/**
 * The OAuth client the `cbox` CLI signs in as.
 *
 * ONE PER ENVIRONMENT, DISCOVERED RATHER THAN BAKED IN. `oauth_clients.client_id`
 * is generated (`cid_…`) and carries a GLOBAL unique index, so there is no
 * well-known value a binary can be compiled with — the same constraint the
 * authenticator app has, answered the same way: the CLI asks the host it was
 * pointed at, at `/.well-known/cbox-cli`, and gets that environment's answer.
 *
 * PUBLIC, WITH TWO GRANTS. A binary on somebody's laptop cannot keep a secret,
 * so it holds none; a client secret shipped inside a PHAR is a secret
 * published. The device grant is how a terminal signs in without a browser on
 * the same machine — over SSH, in CI, in a container — and `refresh_token` is
 * what stops the session ending an hour into somebody's work.
 *
 * FIRST-PARTY, so signing in to our own CLI does not present a consent screen
 * asking a Cbox user to approve Cbox.
 */
final class CliClient
{
    public const NAME = 'Cbox CLI';

    /**
     * `offline_access` is not optional here: without it there is no refresh
     * token, and every session ends when the access token does — an hour in,
     * mid-work, which is how people end up pasting long-lived API keys instead.
     *
     * @var list<string>
     */
    public const SCOPES = ['openid', 'profile', 'email', 'offline_access'];

    /** @var list<string> */
    public const GRANTS = ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'];

    /**
     * This environment's CLI client, if it has been provisioned.
     *
     * Scoped by the tenancy the caller is already inside — the same lookup the
     * authenticator uses, and it is by NAME because the id is generated.
     */
    public static function find(): ?Client
    {
        return Client::query()->where('name', self::NAME)->first();
    }
}
