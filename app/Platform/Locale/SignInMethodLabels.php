<?php

declare(strict_types=1);

namespace App\Platform\Locale;

/**
 * HOW A SESSION WAS SIGNED IN, in words a person reads: "Password", "Approved on your
 * phone". A session records its methods as `amr` codes (`pwd`, `device`, `mfa`), which are
 * the protocol's vocabulary and never shown as they are.
 *
 * `mfa` says only that a second step happened; the step itself (`otp`, `sms`, `device`,
 * `passkey`) says which, so `mfa` is named only when nothing more specific is recorded. A
 * code this list does not know is left out rather than printed.
 */
final class SignInMethodLabels
{
    /** @var array<string, string> amr code => translation key */
    private const array KEYS = [
        'pwd' => 'console.account_amr_pwd',
        'passkey' => 'console.account_amr_passkey',
        'magic_link' => 'console.account_amr_magic_link',
        'otp' => 'console.account_amr_otp',
        'sms' => 'console.account_amr_sms',
        'device' => 'console.account_amr_device',
        'sso' => 'console.enterprise_sso_0f8d7e8e',
        'social' => 'console.social_login_267d75d3',
        'mfa' => 'console.account_amr_mfa',
        'impersonation' => 'console.support_access_851e9a34',
        'invitation' => 'console.account_amr_invitation',
    ];

    /** The second steps `mfa` stands for when none of them is recorded. */
    private const array SECOND_STEPS = ['otp', 'sms', 'device', 'passkey'];

    /**
     * @param  array<int, string>|null  $amr
     * @return list<string>
     */
    public static function for(?array $amr): array
    {
        $amr = array_values(array_unique($amr ?? []));

        if (in_array('mfa', $amr, true) && array_intersect($amr, self::SECOND_STEPS) !== []) {
            $amr = array_values(array_diff($amr, ['mfa']));
        }

        $labels = [];

        foreach ($amr as $code) {
            if (isset(self::KEYS[$code])) {
                $labels[] = ConsoleStrings::message(self::KEYS[$code]);
            }
        }

        return $labels;
    }
}
