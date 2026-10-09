<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Models\Radar\RadarListEntry;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The allow and deny lists. Values are normalised on the way in, so the comparison at sign-in
 * is exact: an address or a domain lower-cased, an IP or CIDR in canonical form, a device as
 * the 64-character pseudonym the decisions explorer shows.
 */
final class RadarLists
{
    /**
     * @return Collection<int, RadarListEntry>
     */
    public function all(?RadarList $list = null, ?RadarListKind $kind = null): Collection
    {
        return RadarListEntry::query()
            ->when($list !== null, static fn ($query) => $query->where('list', $list?->value))
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind?->value))
            ->orderBy('list')
            ->orderBy('kind')
            ->orderBy('value')
            ->get();
    }

    public function find(string $id): ?RadarListEntry
    {
        return RadarListEntry::query()->whereKey($id)->first();
    }

    /**
     * @throws RadarRuleInvalid
     */
    public function add(RadarList $list, RadarListKind $kind, string $value, ?string $note = null, ?Carbon $expiresAt = null): RadarListEntry
    {
        if (RadarListEntry::query()->count() >= RadarListEntry::MAX_ENTRIES) {
            throw new RadarRuleInvalid('An environment may hold at most '.RadarListEntry::MAX_ENTRIES.' list entries.');
        }

        $value = self::normalize($kind, $value);

        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new RadarRuleInvalid('An expiry must be in the future.');
        }

        $note = $note === null ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 255) {
            throw new RadarRuleInvalid('A note may be at most 255 characters.');
        }

        $exists = RadarListEntry::query()
            ->where('list', $list->value)
            ->where('kind', $kind->value)
            ->where('value', $value)
            ->exists();

        if ($exists) {
            throw new RadarRuleInvalid("That {$kind->value} is already on the {$list->value} list.");
        }

        $entry = new RadarListEntry;
        $entry->forceFill([
            'list' => $list,
            'kind' => $kind,
            'value' => $value,
            'note' => $note === '' ? null : $note,
            'expires_at' => $expiresAt,
        ])->save();

        return $entry;
    }

    public function remove(RadarListEntry $entry): void
    {
        $entry->delete();
    }

    /**
     * @throws RadarRuleInvalid
     */
    public static function normalize(RadarListKind $kind, string $value): string
    {
        $value = trim($value);

        $normalized = match ($kind) {
            RadarListKind::Ip => RadarAddresses::canonicalCidr($value),
            RadarListKind::Email => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($value) <= 255
                ? RadarPseudonyms::canonicalEmail($value)
                : null,
            RadarListKind::EmailDomain => preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', strtolower(ltrim($value, '@'))) === 1
                ? strtolower(ltrim($value, '@'))
                : null,
            RadarListKind::Device => preg_match('/^[a-f0-9]{64}$/', strtolower($value)) === 1 ? strtolower($value) : null,
        };

        if ($normalized === null) {
            throw new RadarRuleInvalid(match ($kind) {
                RadarListKind::Ip => 'That is not an IP address or a CIDR range, such as 203.0.113.0/24.',
                RadarListKind::Email => 'That is not an email address.',
                RadarListKind::EmailDomain => 'That is not a mail domain, such as example.com.',
                RadarListKind::Device => 'A device is the 64-character id the decisions explorer shows.',
            });
        }

        return $normalized;
    }
}
