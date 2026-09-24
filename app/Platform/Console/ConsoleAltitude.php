<?php

declare(strict_types=1);

namespace App\Platform\Console;

/**
 * WHICH CONSOLE A PAGE IS DRAWN IN — the question the chrome has to answer before it can
 * say where you are.
 *
 * {@see ConsolePlane} answers which DOOR a request came through, and two of these share
 * one: a workspace's own console at the platform root and an organization's console on a
 * tenant host are both the organization plane. They are not the same place to the person
 * using them — one is the customer's Cbox account, the other is their product — so the
 * shell names the altitude, and the React chrome decides from it what to show (an
 * environment badge means nothing on a workspace; the way back to the workspace means
 * something only in an environment).
 */
enum ConsoleAltitude: string
{
    /** A workspace's own console, at the platform root — see {@see WorkspaceAltitude}. */
    case Workspace = 'workspace';

    /** An organization's console: a tenant host, or a single-tenant install. */
    case Organization = 'organization';

    /** One environment's console, on that environment's own host. */
    case Environment = 'environment';
}
