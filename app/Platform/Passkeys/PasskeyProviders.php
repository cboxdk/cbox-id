<?php

declare(strict_types=1);

namespace App\Platform\Passkeys;

use CBOR\Decoder;
use CBOR\Normalizable;
use CBOR\StringStream;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Throwable;

/**
 * WHICH PASSWORD MANAGER OR AUTHENTICATOR MADE A PASSKEY — read off its AAGUID, for the
 * name it gets by default.
 *
 * Every new passkey carries a 16-byte AAGUID in its attested credential data (WebAuthn
 * §6.5.1) naming the authenticator model: iCloud Keychain, Google Password Manager,
 * 1Password, Windows Hello. "iCloud Keychain" is a far better default than "Passkey" —
 * it is exactly how somebody tells their two passkeys apart a year later.
 *
 * A HINT, NEVER A CLAIM. This deployment asks for `attestation: none`, so the AAGUID is
 * whatever the authenticator said and nothing vouches for it. It only chooses a default
 * label the person sees and can change; nothing decides anything on it. An authenticator
 * that sends zeros, or one not listed here, gets the browser-and-system fallback the page
 * suggested instead.
 *
 * The list is the well-known entries of the community catalogue
 * (github.com/passkeydeveloper/passkey-authenticator-aaguids), kept to the providers
 * people actually meet. Adding one is a line.
 */
final class PasskeyProviders
{
    /** @var array<string, string> AAGUID (lower-case, hyphenated) => provider name */
    public const array NAMES = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud Keychain',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome on Mac',
        'b5397666-4885-aa6b-cebf-e52262a439a2' => 'Chromium',
        '771b48fd-d3d4-4f74-9232-fc157ab0507a' => 'Edge on Mac',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
        '531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
        'b84e4048-15dc-4dd0-8640-f4f60813c8af' => 'NordPass',
        '0ea242b4-43c4-4a1b-8b17-dd6d0b6baec6' => 'Keeper',
        'f3809540-7f14-49c1-a8b3-8f813b225541' => 'Enpass',
        '53414d53-554e-4700-0000-000000000000' => 'Samsung Pass',
        '50726f74-6f6e-5061-7373-50726f746f6e' => 'Proton Pass',
        'fdb141b2-5d84-443e-8a35-4698c205a502' => 'KeePassXC',
        'cb69481e-8ff7-4039-93ec-0a2729a154a8' => 'YubiKey',
        'ee882879-721c-4913-9775-3dfcce97072a' => 'YubiKey',
    ];

    /** The provider a known AAGUID names, or null for an unknown or all-zero one. */
    public static function name(?string $aaguid): ?string
    {
        return $aaguid === null ? null : (self::NAMES[strtolower($aaguid)] ?? null);
    }

    /**
     * The AAGUID in a registration response — the JSON the browser posted — or null when it
     * cannot be read. Called only AFTER the framework has verified that response, so this
     * reads a structure already known to be well formed; it still never throws, because a
     * default name is not worth failing a registration over.
     */
    public static function aaguidFrom(string $registrationJson): ?string
    {
        try {
            $payload = json_decode($registrationJson, true, 16, JSON_THROW_ON_ERROR);
            $encoded = is_array($payload) && is_array($payload['response'] ?? null)
                ? ($payload['response']['attestationObject'] ?? null)
                : null;

            if (! is_string($encoded) || $encoded === '') {
                return null;
            }

            $decoded = Decoder::create()->decode(new StringStream(Base64Url::decode($encoded)));
            $attestation = $decoded instanceof Normalizable ? $decoded->normalize() : null;
            $authData = is_array($attestation) ? ($attestation['authData'] ?? null) : null;

            // rpIdHash(32) ‖ flags(1) ‖ signCount(4) ‖ AAGUID(16) — present only when the
            // attested-credential-data flag (0x40) is set, which a registration always is.
            if (! is_string($authData) || strlen($authData) < 53 || (ord($authData[32]) & 0x40) === 0) {
                return null;
            }

            $hex = bin2hex(substr($authData, 37, 16));

            if ($hex === str_repeat('0', 32)) {
                return null;
            }

            return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The name a new passkey starts with: the provider's when the AAGUID names one, else
     * the page's suggestion when it is a name a passkey may carry, else "Passkey".
     */
    public static function defaultName(string $registrationJson, ?string $suggested): string
    {
        return self::name(self::aaguidFrom($registrationJson))
            ?? PasskeyName::normalize($suggested)
            ?? PasskeyName::FALLBACK;
    }
}
