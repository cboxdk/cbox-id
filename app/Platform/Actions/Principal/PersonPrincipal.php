<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionPlane;

/**
 * A PERSON acting through a credential they delegated — the one kind of principal the
 * account plane ({@see ActionPlane::Account}) accepts over REST.
 *
 * WHO IMPLEMENTS IT. A token an environment issued one of its own subjects
 * ({@see DelegatedTokenPrincipal}), and a token the platform root issued a workspace
 * member ({@see RootPersonPrincipal}) — or an operator ({@see RootOperatorPrincipal}, an
 * {@see OperatorPrincipal}). {@see DelegatedTokens} hands them to the REST door. In the
 * console a {@see ConsoleSessionPrincipal} answers the same questions from the session.
 *
 * What a person's principal adds to {@see Principal} is WHO: every account action is
 * keyed to {@see self::subjectId()}, never to an id the caller names, so there is no
 * account a token could act on but its own person's.
 */
interface PersonPrincipal extends Principal
{
    /** The person — a subject of the environment whose host this request is on. */
    public function subjectId(): string;

    /**
     * The sign-in session this credential belongs to, if it is one: "sign me out
     * everywhere else" keeps it. A token that is no session answers null, and everywhere
     * else is everywhere.
     */
    public function currentSessionId(): ?string;

    /** Whether the person delegated $scope to this credential. */
    public function grants(string $scope): bool;
}
