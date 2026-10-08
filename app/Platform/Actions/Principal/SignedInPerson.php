<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\EnvironmentKeyAuditLog;

/**
 * A PERSON acting through an OAuth client they signed in — an agent, the `cbox` CLI — as
 * opposed to a key, which is nobody, or a console session, which is the person at their
 * own browser.
 *
 * What every such principal can say beside {@see Principal}: who the person is, which
 * client carries their token, and when it ends. The trail records the client on every
 * entry the person's act causes ({@see EnvironmentKeyAuditLog}), because "the person did
 * it" and "the person's agent did it" are different answers to an auditor.
 *
 * Three implement it: a token an environment issued to one of its own subjects
 * ({@see DelegatedTokenPrincipal}); a token the platform root issued to a workspace member
 * or an operator ({@see RootPersonPrincipal}); and that same root token, bound for one call
 * to one environment of the person's workspace ({@see EnvironmentMemberPrincipal}).
 */
interface SignedInPerson extends Principal
{
    /** The person — a subject of the environment that issued the token. */
    public function subjectId(): string;

    public function personName(): string;

    /** The OAuth client the person signed in: a registered id, or a metadata document URL. */
    public function clientId(): string;

    /** What the person knows the client as. */
    public function clientName(): string;

    /**
     * The scopes the token carries that this deployment's planes define — what `whoami`
     * reports. The protocol scopes (`openid`, `offline_access`) grant nothing here.
     *
     * @return list<string>
     */
    public function managementScopes(): array;

    /** When the token expires, as a Unix time; null when it does not say. */
    public function expiresAt(): ?int;
}
