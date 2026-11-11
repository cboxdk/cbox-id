<?php

declare(strict_types=1);

use App\Platform\PublicIds\IdNames;
use App\Platform\PublicIds\PublicId;
use App\Platform\PublicIds\PublicIdTree;
use App\Platform\PublicIds\WebhookIdFormat;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| Plan step 7: public ids are `<prefix>_<ULID>` at the boundary, the bare ULID is still read
| everywhere, and a prefix of the wrong kind names nothing.
*/

const A_ULID = '01jcz8x7t4m2k9q3r5v6w8y0ab';

it('registers one distinct prefix per kind, and not the OAuth client_id', function (): void {
    expect(PublicId::PREFIXES)->toHaveCount(47)
        ->and(array_unique(PublicId::PREFIXES))->toHaveCount(47)
        ->and(PublicId::PREFIXES)->not->toContain('cid');

    foreach (PublicId::PREFIXES as $prefix) {
        expect($prefix)->toMatch('/\A[a-z]+\z/');
    }
});

it('round-trips every kind', function (string $type): void {
    $public = PublicId::encode($type, A_ULID);

    expect($public)->toBe(PublicId::prefix($type).'_'.A_ULID)
        ->and(PublicId::decode($public, $type))->toBe(A_ULID)
        ->and(PublicId::decode(A_ULID, $type))->toBe(A_ULID)
        ->and(PublicId::isOf($type, $public))->toBeTrue();
})->with(array_keys(PublicId::PREFIXES));

it('prefixes only a bare ULID, so encoding twice or encoding a slug changes nothing', function (): void {
    $once = PublicId::encode('user', A_ULID);

    expect(PublicId::encode('user', $once))->toBe($once)
        ->and(PublicId::encode('organization', 'acme'))->toBe('acme')
        ->and(PublicId::encode('environment', 'env_test'))->toBe('env_test')
        ->and(PublicId::encode('app', 'cid_'.A_ULID))->toBe('cid_'.A_ULID)
        ->and(PublicId::encode('user', strtoupper(A_ULID)))->toBe('usr_'.strtoupper(A_ULID));
});

it('answers a public id of the wrong kind as a record that is not there', function (): void {
    PublicId::decode('org_'.A_ULID, 'user');
})->throws(NotFoundHttpException::class);

it('leaves what is not a registered public id for the handler to look up', function (): void {
    expect(PublicId::decode('cid_'.A_ULID, 'user'))->toBe('cid_'.A_ULID)
        ->and(PublicId::decode('zzz_'.A_ULID, 'user'))->toBe('zzz_'.A_ULID)
        ->and(PublicId::decode('env_test', 'environment'))->toBe('env_test')
        ->and(PublicId::decode('acme', 'organization'))->toBe('acme');
});

it('names the kind of a field, a nested object, an object and a path resource', function (): void {
    expect(IdNames::field('organization_id'))->toBe('organization')
        ->and(IdNames::field('environment_ids'))->toBe('environment')
        ->and(IdNames::field('access_review_id'))->toBe('access_review')
        ->and(IdNames::field('client_id'))->toBeNull()
        ->and(IdNames::field('actor_id'))->toBeNull()
        ->and(IdNames::nested('organizations'))->toBe('organization')
        ->and(IdNames::object('member'))->toBe('membership')
        ->and(IdNames::resource('organization-memberships'))->toBe('membership');
});

it('writes public ids through a whole document, and nowhere it should not', function (): void {
    $out = PublicIdTree::encode([
        'id' => A_ULID,
        'user_id' => A_ULID,
        'organization_id' => A_ULID,
        'environment_ids' => [A_ULID],
        'client_id' => 'cid_'.A_ULID,
        'sub' => A_ULID,
        'org_id' => A_ULID,
        'organizations' => [['id' => A_ULID, 'slug' => 'acme']],
        'actor' => ['id' => A_ULID],
        'metadata' => ['user_id' => A_ULID],
        'next_cursor' => A_ULID,
    ], 'membership');

    expect($out)->toBe([
        'id' => 'mbr_'.A_ULID,
        'user_id' => 'usr_'.A_ULID,
        'organization_id' => 'org_'.A_ULID,
        'environment_ids' => ['env_'.A_ULID],
        'client_id' => 'cid_'.A_ULID,
        // Token claims are not ids this round touches (plan §8 Q2).
        'sub' => A_ULID,
        'org_id' => A_ULID,
        'organizations' => [['id' => 'org_'.A_ULID, 'slug' => 'acme']],
        // Somebody else's data keeps the ids it was given.
        'actor' => ['id' => A_ULID],
        'metadata' => ['user_id' => A_ULID],
        'next_cursor' => A_ULID,
    ]);
});

it('reads public ids back out of a request\'s fields', function (): void {
    expect(PublicIdTree::decode([
        'organization_id' => 'org_'.A_ULID,
        'user_id' => A_ULID,
        'environment_ids' => ['env_'.A_ULID, A_ULID],
        'name' => 'org_'.A_ULID,
    ]))->toBe([
        'organization_id' => A_ULID,
        'environment_ids' => [A_ULID, A_ULID],
    ]);
});

it('refuses a request field holding another kind\'s public id', function (): void {
    PublicIdTree::decode(['organization_id' => 'usr_'.A_ULID]);
})->throws(NotFoundHttpException::class);

it('knows the record an event is about', function (): void {
    expect(WebhookIdFormat::subjectOf('user.created'))->toBe('user')
        ->and(WebhookIdFormat::subjectOf('id.user.created'))->toBe('user')
        ->and(WebhookIdFormat::subjectOf('directory.user.provisioned'))->toBe('directory_user')
        ->and(WebhookIdFormat::subjectOf('organization.created'))->toBe('organization')
        ->and(WebhookIdFormat::subjectOf('role.assigned'))->toBeNull()
        ->and(WebhookIdFormat::payload('organization.created', ['id' => A_ULID, 'parent_id' => A_ULID]))
        ->toBe(['id' => 'org_'.A_ULID, 'parent_id' => 'org_'.A_ULID]);
});
