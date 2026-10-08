<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Actions\Principal\PortalPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Support\CliClient;
use Illuminate\Http\Request;

/**
 * WHICH DOOR AN ACTION CAME THROUGH — the console, the REST API, an MCP tool call, the
 * `cbox` CLI or the hosted Admin Portal.
 *
 * The principal says WHO acted; this says HOW. They are different questions with
 * different answers: a management key reaches the same action over REST and over MCP, and
 * a person reaches it from the console and from the CLI they signed in. An auditor asking
 * "what did agents change this week" is asking the second question, and the trail could
 * not answer it — every entry looked the same whichever door it came through.
 *
 * Named by the door that ran the action ({@see ActionRunner::run()}), recorded on every
 * audit entry the action causes ({@see ActionTrail}), and filtered on the audit log
 * (`?via=mcp`).
 */
enum ActionVia: string
{
    case Console = 'console';
    case Rest = 'rest';
    case Mcp = 'mcp';
    case Cli = 'cli';

    /**
     * The hosted Admin Portal: a customer's IT administrator under a one-time link
     * ({@see PortalPrincipal}).
     */
    case Portal = 'portal';

    /**
     * The door when the caller did not name one: the console for a console session, the
     * Admin Portal for a portal session, the REST API for everything else.
     */
    public static function inferredFrom(Principal $principal): self
    {
        return match ($principal->kind()) {
            'console' => self::Console,
            'portal' => self::Portal,
            default => self::Rest,
        };
    }

    /**
     * The REST door, told apart from the CLI that also uses it.
     *
     * The CLI is recognised by the credential before the header: a person's token minted
     * for this environment's own CLI client IS the CLI, whatever it claims to be. A key
     * carries no client, so the CLI's `User-Agent` (`cbox-cli/…`) is the only thing that
     * can say so — a label, not a trust decision: nothing is allowed or refused on it.
     */
    public static function overRest(Principal $principal, Request $request): self
    {
        if ($principal instanceof DelegatedTokenPrincipal) {
            $cli = CliClient::find();

            if ($cli !== null && $principal->clientId() === $cli->client_id) {
                return self::Cli;
            }
        }

        return str_starts_with(strtolower((string) $request->userAgent()), 'cbox-cli/') ? self::Cli : self::Rest;
    }

    public function label(): string
    {
        return match ($this) {
            self::Console => 'Console',
            self::Rest => 'REST API',
            self::Mcp => 'MCP',
            self::Cli => 'CLI',
            self::Portal => 'Admin Portal',
        };
    }
}
