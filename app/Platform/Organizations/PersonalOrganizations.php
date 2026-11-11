<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use App\Platform\PlaneResolver;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Exceptions\SlugAlreadyTaken;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person's own organization in the Cbox realm: an ordinary organization named after
 * them, which they own, created when they sign up without an invitation or when they are
 * left with no organization at all.
 *
 * ORDINARY is the point. It can be renamed, given members, handed over and closed like any
 * other; `settings.personal_for` records whose it was founded as, survives a rename, and is
 * a hint for the interface only. Nothing may be allowed or refused because of it.
 *
 * Only in the realm itself (the platform root of a multi-tenant deployment) and only with
 * `cbox-id.signup.personal_organization` on. A customer's own environment keeps its own
 * signup rules, and a single-tenant install has no realm.
 */
final readonly class PersonalOrganizations
{
    /** The key in an organization's settings naming the person it was founded for. */
    public const SETTING = 'personal_for';

    public function __construct(
        private PlaneResolver $planes,
        private Organizations $organizations,
        private Memberships $memberships,
    ) {}

    /** Whether this request stands in the realm and the switch is on. */
    public function enabled(): bool
    {
        return config('cbox-id.signup.personal_organization') === true && $this->planes->onAccountPlane();
    }

    public static function isPersonal(Organization $organization): bool
    {
        $for = $organization->settings[self::SETTING] ?? null;

        return is_string($for) && $for !== '';
    }

    /**
     * The person's personal organization, founded now unless they still own a live one.
     *
     * Idempotent, under a lock per person: a double submit, or two tabs on the same
     * screen, end up in the same organization instead of two. The organization and its
     * owner membership commit together, so there is never one nobody owns.
     *
     * Runs in the ambient environment; callers stand in the realm ({@see enabled()}).
     */
    public function ensureFor(Subject $subject): Organization
    {
        $lock = Cache::lock('personal-organization|'.$subject->id, 10);
        $lock->block(5);

        try {
            return $this->existingFor($subject->id) ?? DB::transaction(function () use ($subject): Organization {
                $organization = $this->createWithFreeSlug($subject);
                $this->memberships->add($organization->id, $subject->id, MembershipRole::Owner);

                return $organization;
            });
        } finally {
            $lock->release();
        }
    }

    /** The live personal organization this person founded and still actively owns, if any. */
    public function existingFor(string $subjectId): ?Organization
    {
        $owned = [];

        foreach ($this->memberships->forUser($subjectId) as $membership) {
            if ($membership->status === MembershipStatus::Active && $membership->role === MembershipRole::Owner) {
                $owned[] = $membership->organization_id;
            }
        }

        foreach ($this->organizations->findMany($owned) as $organization) {
            if ($organization->status === OrganizationStatus::Active
                && ($organization->settings[self::SETTING] ?? null) === $subjectId) {
                return $organization;
            }
        }

        return null;
    }

    private function createWithFreeSlug(Subject $subject): Organization
    {
        $name = $this->nameFor($subject);
        $base = mb_substr(Str::slug($name) ?: 'personal', 0, 60);
        $slug = $base;
        $n = 1;

        while ($this->organizations->bySlug($slug) !== null && $n < 50) {
            $slug = $base.'-'.(++$n);
        }

        $settings = [self::SETTING => $subject->id];

        try {
            return $this->organizations->create(new NewOrganization($name, $slug, settings: $settings));
        } catch (SlugAlreadyTaken) {
            return $this->organizations->create(
                new NewOrganization($name, $base.'-'.Str::lower(Str::random(6)), settings: $settings),
            );
        }
    }

    /** The person's name; the part of their address before the @ when they gave none. */
    private function nameFor(Subject $subject): string
    {
        $name = trim((string) $subject->name);

        if ($name === '') {
            $name = trim(Str::before((string) $subject->email, '@'));
        }

        return mb_substr($name !== '' ? $name : 'Personal', 0, 120);
    }
}
