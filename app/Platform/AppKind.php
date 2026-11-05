<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Locale\ConsoleStrings;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * What kind of application is being registered — the ONE question the console asks
 * instead of the four it used to.
 *
 * Registering an app meant answering, separately: is it confidential or public, does it
 * use `authorization_code`, does it use `client_credentials`, and which scopes. Those are
 * not four decisions. They are one decision — "what am I building?" — expressed four
 * times in the vocabulary of the specification rather than of the person building it.
 * Nobody sets out to write a confidential client with the client-credentials grant; they
 * set out to write a service that calls an API.
 *
 * The cost of asking it the old way was not only difficulty. It was ABSENCE: a CLI and an
 * agent had no combination of those four answers that produced a working app, because the
 * device and CIBA grants were not among the checkboxes at all. The one flow this platform
 * is best at was unreachable from the page that registers apps for it.
 *
 * Each kind names its own grants and client type, so the console cannot offer a
 * combination the token endpoint refuses. {@see self::Advanced} is the escape hatch: it
 * hands the old controls back for the case none of these describes.
 */
enum AppKind: string
{
    case WebApp = 'web';
    case SpaOrMobile = 'spa';
    case CliOrDevice = 'cli';
    case Service = 'service';
    case Agent = 'agent';
    case Advanced = 'advanced';

    /**
     * The kinds offered on the form, in the order they are offered.
     *
     * Web first because it is most of what people build; Advanced last because it is the
     * answer to a question this list failed to ask.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return [self::WebApp, self::SpaOrMobile, self::CliOrDevice, self::Service, self::Agent, self::Advanced];
    }

    /**
     * Which kind an already-registered app is, read back from its grants.
     *
     * The grants are what the token endpoint actually enforces, so they are the honest
     * source — a stored `kind` column would be a second answer to the same question, free
     * to drift from the one that decides anything. Anything that does not match a preset
     * is Advanced, which is exactly what it is.
     */
    public static function forClient(Client $client): self
    {
        $grants = $client->grant_types ?? [];

        foreach ([self::WebApp, self::SpaOrMobile, self::CliOrDevice, self::Service, self::Agent] as $kind) {
            if ($kind->grantTypes() === $grants && $kind->clientType() === $client->type) {
                return $kind;
            }
        }

        return self::Advanced;
    }

    public function label(): string
    {
        return match ($this) {
            self::WebApp => ConsoleStrings::message('console.web_app_f2dc406d'),
            self::SpaOrMobile => ConsoleStrings::message('console.single_page_or_mobile_app_2cdb4280'),
            self::CliOrDevice => ConsoleStrings::message('console.cli_or_device_9380493d'),
            self::Service => ConsoleStrings::message('console.service_or_background_job_d8a0400a'),
            self::Agent => ConsoleStrings::message('console.ai_agent_92c7d7f8'),
            self::Advanced => ConsoleStrings::message('console.something_else_cc728431'),
        };
    }

    /**
     * What someone recognises their own project by — a framework, a runtime, a shape.
     * Written as examples rather than as a definition, because a person choosing from
     * this list is matching, not classifying.
     */
    public function description(): string
    {
        return match ($this) {
            self::WebApp => ConsoleStrings::message('console.runs_on_a_server_and_can_keep_a_secret_larav_5f242f75'),
            self::SpaOrMobile => ConsoleStrings::message('console.runs_on_the_device_so_it_holds_no_secret_rea_bcd752f3'),
            self::CliOrDevice => ConsoleStrings::message('console.no_browser_of_its_own_a_terminal_a_ci_job_a__3abb52d5'),
            self::Service => ConsoleStrings::message('console.calls_the_api_as_itself_with_no_person_invol_ecd6923a'),
            self::Agent => ConsoleStrings::message('console.acts_on_somebody_s_behalf_and_asks_them_to_a_6327932e'),
            self::Advanced => ConsoleStrings::message('console.pick_the_grants_and_client_type_yourself_1e7163c1'),
        };
    }

    /**
     * Confidential means "can keep a secret", which is a fact about where the code runs
     * rather than a preference. A binary on a laptop and a bundle in a browser cannot,
     * whatever the person filling in this form would prefer.
     */
    public function clientType(): ClientType
    {
        return match ($this) {
            self::SpaOrMobile, self::CliOrDevice => ClientType::Public,
            default => ClientType::Confidential,
        };
    }

    /**
     * @return list<string>
     */
    public function grantTypes(): array
    {
        return match ($this) {
            // `refresh_token` travels with every person-facing grant: without it the session
            // ends an hour into somebody's work and they are sent back through sign-in.
            self::WebApp, self::SpaOrMobile => ['authorization_code', 'refresh_token'],
            self::CliOrDevice => ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'],
            self::Service => ['client_credentials'],
            // The agent gets a token of its own for the work it does unattended, and CIBA
            // for the step where a person has to say yes.
            self::Agent => ['urn:openid:params:grant-type:ciba', 'client_credentials'],
            self::Advanced => [],
        };
    }

    /**
     * Whether this kind is returned to a browser address, and therefore needs one.
     *
     * The device grant's whole point is that it is not — a CLI has no callback URL, and
     * asking for one was the field that made people believe they had chosen wrong.
     */
    public function needsRedirectUris(): bool
    {
        return in_array('authorization_code', $this->grantTypes(), true);
    }

    /**
     * Whether a person is present at all. A service's token says nothing about anybody,
     * so the sign-in scopes are noise on its form and `openid` on it is a lie.
     */
    public function signsPeopleIn(): bool
    {
        return $this !== self::Service && $this !== self::Advanced;
    }

    /**
     * The scopes this kind is registered for unless the person changes them.
     *
     * REGISTERED, not requested — this is the ceiling. A device or CIBA request naming a
     * scope outside it is refused outright rather than quietly downscoped, because those
     * flows have no browser in front of them to notice a smaller grant, so a ceiling that
     * is too tight surfaces as an error the person has to diagnose.
     *
     * `offline_access` rides along wherever `refresh_token` does, for the same reason the
     * grant does.
     *
     * @return list<string>
     */
    public function defaultScopes(): array
    {
        return match ($this) {
            self::WebApp, self::SpaOrMobile => ['openid', 'profile', 'email', 'offline_access'],
            self::CliOrDevice => ['openid', 'profile', 'email', 'offline_access'],
            self::Agent => ['openid', 'profile', 'email'],
            self::Service => [],
            self::Advanced => ['openid', 'profile', 'email'],
        };
    }

    /**
     * The handshake, in the order it happens, for the diagram on the form.
     *
     * Four steps at most: a person reading a picture to decide whether they picked the
     * right box is not reading a specification.
     *
     * @return list<string>
     */
    public function flow(): array
    {
        return match ($this) {
            self::WebApp, self::SpaOrMobile => [
                'Person clicks <b>Sign in</b> in your app',
                'Redirected to <b>Cbox ID</b> to authenticate',
                'Redirected <b>back to your app</b> with a code',
                'Your app swaps the code for tokens',
            ],
            self::CliOrDevice => [
                'Your CLI prints a <b>short code</b> and a URL',
                'Person opens it on <b>any other device</b> and approves',
                'Your CLI polls and receives its <b>tokens</b>',
            ],
            self::Service => [
                'Your service sends its <b>ID + secret</b> to Cbox ID',
                'Cbox ID returns an <b>access token</b>',
                'Your service calls the <b>API</b> with the token',
            ],
            self::Agent => [
                'Your agent asks Cbox ID to <b>get approval</b> from a person',
                'They approve on a <b>device they already have</b>',
                'Your agent polls and receives a token <b>for that person</b>',
            ],
            self::Advanced => [],
        };
    }
}
