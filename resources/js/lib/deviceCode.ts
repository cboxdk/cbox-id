/**
 * A device `user_code` as it is typed — `bcdf ghjk` → `BCDF-GHJK`.
 *
 * Capitals, letters and digits only, and the dash after the fourth, put in as the person
 * types so the field always shows the shape their TV does. The server normalises the same
 * way (`App\Platform\OAuth\DeviceUserCode`), so what this does is a courtesy to the eye,
 * not the rule: a code pasted with a space, or typed without the dash, is the same code.
 */
export function formatDeviceCode(value: string): string {
    const letters = value
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 8);

    return letters.length > 4 ? `${letters.slice(0, 4)}-${letters.slice(4)}` : letters;
}
