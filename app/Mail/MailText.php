<?php

declare(strict_types=1);

namespace App\Mail;

use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * THE TWO THINGS EVERY MAIL TEMPLATE NEEDS FROM THE CATALOGUE.
 *
 * The product's NAME — the deployment's configured brand, the same one the subject lines
 * already used, rather than the "Cbox ID" the bodies had hard-coded: a self-hosted
 * install that renamed itself was mailing its users our name under its own subject.
 *
 * And a SENTENCE WITH A BOLD NAME IN IT, kept whole. "<b>Ada</b> invited you to join
 * <b>Acme</b>" cannot be three keys around two tags — German and French put the object
 * elsewhere in the sentence — so the line is translated with placeholders, ESCAPED as a
 * whole, and only then are the names (escaped themselves) dropped in wrapped in `<b>`.
 * Nothing from the catalogue and nothing from the inviter reaches the HTML unescaped.
 */
final class MailText
{
    public static function brand(): string
    {
        $brand = config('cbox-id.branding.name', 'Cbox ID');

        return is_string($brand) && $brand !== '' ? $brand : 'Cbox ID';
    }

    /**
     * The environment's UPLOADED logo as an absolute URL on this application, or null.
     *
     * Absolute because a mail client has no page to resolve a path against; on this
     * application because the alternative — the remote logo URL the old Appearance page used to
     * take — is a tracking pixel in every inbox it reaches, reporting when each message was
     * opened and from where. The environment's default, not an organization's: mail is sent
     * on the environment's behalf. Null outside an environment, and on any failure — a
     * missing logo must never stop a sign-in link from being sent.
     */
    public static function logo(): ?string
    {
        try {
            if (! app(EnvironmentContext::class)->has()) {
                return null;
            }

            return app(BrandImages::class)->absoluteUrl(BrandImage::Logo, null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $bold  placeholders drawn in `<b>`
     * @param  array<string, string>  $plain  placeholders drawn as text
     */
    public static function html(string $key, array $bold = [], array $plain = []): HtmlString
    {
        $tokens = [];
        $replace = $plain;

        foreach ($bold as $name => $value) {
            // Private-use code points: no catalogue line and no name contains them, and
            // the escaper leaves them alone, so they survive to be swapped back out.
            $token = "\u{E000}{$name}\u{E001}";
            $tokens[$token] = '<b>'.e($value).'</b>';
            $replace[$name] = $token;
        }

        $line = __($key, $replace);

        return new HtmlString(strtr(e(is_string($line) ? $line : $key), $tokens));
    }

    /**
     * A role's name in the reader's language when it is one of the platform's own — the
     * invitation names it as the inviter's console labelled it otherwise.
     */
    public static function role(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $key = 'mail.roles.'.strtolower($label);
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : $label;
    }
}
