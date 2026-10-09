<?php

declare(strict_types=1);

namespace App\Platform\Portal;

use Cbox\Id\Directory\Hris\HrisCatalog;
use Cbox\Id\Directory\Hris\ValueObjects\HrisCredential;
use Cbox\Id\Directory\Hris\ValueObjects\HrisSetup;
use Cbox\Id\Federation\Enums\SpValue;
use Cbox\Id\Federation\IdentityProviderGuides;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\GuideField;
use Cbox\Id\Federation\ValueObjects\IdentityProviderGuide;
use Cbox\Id\Federation\ValueObjects\ScimDirectoryGuide;
use Cbox\Id\Federation\ValueObjects\ServiceProviderValues;

/**
 * STEP-BY-STEP GUIDES for the identity providers an IT administrator actually runs, as the
 * Admin Portal shows them: which screen to open, what to click, and — the part people get
 * wrong — WHICH of our values goes into WHICH of their fields, by the name their admin
 * screen gives that field.
 *
 * THE DATA IS THE FRAMEWORK'S ({@see IdentityProviderGuides}): twenty identity providers and
 * the SCIM half of the nine that can push to a custom app, every field label read off the
 * vendor's own documentation. This class used to hold its own copy of eight of them, and a
 * copy is how "Application username" stayed on the page after Okta renamed the field
 * "Application username format". What stays here is the portal's half: the props shape the
 * pages draw, and the step TEXT in the visitor's language.
 *
 * Not {@see ProviderCatalog}. That is the providers WE sign in to with OAuth credentials the
 * customer creates (Google, GitHub, Apple…); this is the opposite direction — enterprise
 * identity providers that sign their people in to US over SAML or OIDC, and directories
 * that push their people to us over SCIM.
 *
 * THE STEPS are translated (`lang/{locale}/portal.php`, `portal.guides.{sso|directory}.{key}`),
 * worded for this page — our values are listed above the steps, theirs are pasted below.
 * A guide the framework adds before anybody has translated it falls back to its English
 * `setupSteps`, so a new identity provider is offered at once rather than hidden until a
 * translation lands. The field LABELS are never translated: "Audience URI (SP Entity ID)"
 * is what the person will find on Okta's screen, whatever language this page is in.
 *
 * `fields` maps each of OUR values ({@see SpValue}: `acs_url`, `entity_id`, `sp_metadata_url`,
 * `slo_url`, `login_url`, `redirect_uri`, `scim_base_url`, `scim_token`, the derived
 * `acs_regex` / `scim_host` / `scim_base_path`, and a `literal` the same for everybody) to
 * THEIR field; {@see self::values()} supplies ours for one connection. `returns` names what
 * they bring back from their screen.
 */
final class PortalGuides
{
    /** The generic SCIM guide's key — {@see IdentityProviderGuides::genericDirectory()} has none of its own. */
    public const string GENERIC_DIRECTORY = 'scim';

    /**
     * The single sign-on guides, in the order the portal offers them: the framework's, the
     * most common first and the two generic ones last.
     *
     * @return list<array{key: string, name: string, protocol: string, fields: list<array<string, string|bool>>, returns: array{kind: string, theirs: string}, docs: ?string, steps: list<string>}>
     */
    public static function sso(): array
    {
        return array_map(static fn (IdentityProviderGuide $guide): array => [
            'key' => $guide->key,
            'name' => $guide->name,
            'protocol' => $guide->protocol->value,
            'fields' => self::fields($guide->fields),
            'returns' => ['kind' => $guide->returns->kind->value, 'theirs' => $guide->returns->theirs],
            'docs' => $guide->documentationUrl,
            'steps' => self::steps('sso', $guide->key, $guide->setupSteps),
        ], IdentityProviderGuides::all());
    }

    /**
     * The directory-sync guides — SCIM 2.0, which the customer's directory speaks to us —
     * and the generic one last, for a directory with no guide of its own.
     *
     * @return list<array{key: string, name: string, fields: list<array<string, string|bool>>, docs: ?string, steps: list<string>}>
     */
    public static function directories(): array
    {
        $guides = [];

        foreach (IdentityProviderGuides::directories() as $guide) {
            if ($guide->directory !== null) {
                $guides[] = self::directory($guide->key, $guide->name, $guide->directory);
            }
        }

        $guides[] = self::directory(self::GENERIC_DIRECTORY, 'SCIM 2.0', IdentityProviderGuides::genericDirectory());

        return $guides;
    }

    /**
     * The HR-system guides — Workday, BambooHR, Rippling, HiBob, Personio — from the
     * framework's {@see HrisCatalog}, which is checked against the connectors: the fields a
     * guide asks for are exactly the ones the sync reads.
     *
     * The opposite of the SCIM guides in one respect: here the customer gives US values
     * (an API key, a report address) rather than taking ours, so a guide is its steps plus
     * the fields to fill in. Steps, field labels and help are translated
     * (`portal.guides.hris.{key}`, `portal.hris.fields.{field}`, `portal.hris.help.{key}.{field}`),
     * each falling back to the framework's English, so an HR system the framework adds is
     * offered at once.
     *
     * @return list<array{key: string, name: string, docs: string, incremental: bool, steps: list<string>, credentials: list<array{key: string, label: string, help: string, example: string, secret: bool, required: bool}>}>
     */
    public static function hris(): array
    {
        return array_map(static fn (HrisSetup $setup): array => [
            'key' => $setup->provider->value,
            'name' => $setup->name,
            'docs' => $setup->documentationUrl,
            'incremental' => $setup->incremental,
            'steps' => self::steps('hris', $setup->provider->value, $setup->setupSteps),
            'credentials' => array_map(static fn (HrisCredential $credential): array => [
                'key' => $credential->key,
                'label' => self::line("portal.hris.fields.{$credential->key}", $credential->label),
                'help' => self::line("portal.hris.help.{$setup->provider->value}.{$credential->key}", $credential->help),
                'example' => $credential->example,
                'secret' => $credential->secret,
                'required' => $credential->required,
            ], $setup->credentials),
        ], HrisCatalog::all());
    }

    /** @return list<string> */
    public static function hrisKeys(): array
    {
        return array_column(self::hris(), 'key');
    }

    /** @return list<string> */
    public static function ssoKeys(): array
    {
        return IdentityProviderGuides::keys();
    }

    /** @return list<string> */
    public static function directoryKeys(): array
    {
        return array_column(self::directories(), 'key');
    }

    /**
     * OUR values for one connection or directory, keyed the way a guide's fields name them
     * ({@see SpValue}) — the derived ones (OneLogin's ACS pattern, Oracle's SCIM host and
     * path) derived by the framework, so every console derives them alike. A value the
     * connection does not have is left out, and its line is not drawn.
     *
     * The SCIM token is never in here: it exists in plaintext only on the one response
     * that minted it, and the page holds it from the flash.
     *
     * @return array<string, string>
     */
    public static function values(ServiceProviderValues $ours): array
    {
        $values = [];

        foreach (SpValue::cases() as $value) {
            if ($value === SpValue::Literal || $value->isSecret()) {
                continue;
            }

            $resolved = $ours->for($value);

            if ($resolved !== null) {
                $values[$value->value] = $resolved;
            }
        }

        return $values;
    }

    /**
     * @return array{key: string, name: string, fields: list<array<string, string|bool>>, docs: ?string, steps: list<string>}
     */
    private static function directory(string $key, string $name, ScimDirectoryGuide $guide): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'fields' => self::fields($guide->fields),
            'docs' => $guide->documentationUrl,
            'steps' => self::steps('directory', $key, $guide->setupSteps),
        ];
    }

    /**
     * @param  list<GuideField>  $fields
     * @return list<array<string, string|bool>>
     */
    private static function fields(array $fields): array
    {
        return array_map(static fn (GuideField $field): array => array_filter([
            'ours' => $field->ours->value,
            'theirs' => $field->theirs,
            'literal' => $field->literal,
            'optional' => $field->optional ?: null,
            'location' => $field->location,
        ], static fn (string|bool|null $value): bool => $value !== null), $fields);
    }

    /** One translated line, or the framework's English when nobody has translated it yet. */
    private static function line(string $key, string $english): string
    {
        $line = __($key);

        return is_string($line) && $line !== $key ? $line : $english;
    }

    /**
     * A guide's steps in the visitor's language, or the framework's English when nobody
     * has translated this guide yet.
     *
     * @param  list<string>  $english
     * @return list<string>
     */
    private static function steps(string $kind, string $key, array $english): array
    {
        $line = "portal.guides.{$kind}.{$key}";
        $steps = __($line);

        return is_array($steps)
            ? array_values(array_filter($steps, 'is_string'))
            : $english;
    }
}
