<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * An approval's public id moved from `apr_` to `idapr_` (2026-11-26). Stored deliveries to
 * endpoints that receive public ids are sent again as they are on a retry, so the migration
 * rewrites the approval ids Cbox ID wrote in them, and nothing a customer wrote.
 */
function approvalPrefixDelivery(mixed $payload): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('webhook_deliveries')->insert([
        'id' => $id,
        'environment_id' => 'env_test',
        'endpoint_id' => strtolower((string) Str::ulid()),
        'event_type' => 'id.audit.recorded',
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function approvalPrefixPayload(string $id): mixed
{
    return json_decode((string) DB::table('webhook_deliveries')->where('id', $id)->value('payload'), false, 512, JSON_THROW_ON_ERROR);
}

it('rewrites the approval ids Cbox ID wrote in stored deliveries, and only those', function (): void {
    $a = strtolower((string) Str::ulid());
    $b = strtoupper((string) Str::ulid());
    $migration = require database_path('migrations/2026_11_26_120000_rename_the_approval_public_id_prefix.php');

    $written = [
        'type' => 'id.audit.recorded',
        'data' => [
            'approval_id' => "apr_{$a}",
            'approval_ids' => ["apr_{$a}", "apr_{$b}", 'apr_not-an-id'],
            'approval' => ['object' => 'approval', 'id' => "apr_{$b}", 'status' => 'approved'],
            'user_id' => "usr_{$a}",
            // A customer's own data, and text that only looks like an id: as they were.
            'metadata' => ['approval_id' => "apr_{$a}"],
            'name' => "apr_{$a}",
            'settings' => new stdClass,
        ],
    ];
    $rewritten = approvalPrefixDelivery($written);
    $untouched = approvalPrefixDelivery(['type' => 'id.user.created', 'data' => ['id' => $a, 'note' => "idapr_{$a} apr_{$a}"]]);
    $before = DB::table('webhook_deliveries')->where('id', $untouched)->value('payload');

    $migration->up();

    $data = approvalPrefixPayload($rewritten)->data;

    expect($data->approval_id)->toBe("idapr_{$a}")
        ->and($data->approval_ids)->toBe(["idapr_{$a}", "idapr_{$b}", 'apr_not-an-id'])
        ->and($data->approval->id)->toBe("idapr_{$b}")
        ->and($data->user_id)->toBe("usr_{$a}")
        ->and($data->metadata->approval_id)->toBe("apr_{$a}")
        ->and($data->name)->toBe("apr_{$a}")
        ->and($data->settings)->toEqual(new stdClass)
        ->and(DB::table('webhook_deliveries')->where('id', $untouched)->value('payload'))->toBe($before);

    $migration->down();

    expect(approvalPrefixPayload($rewritten))->toEqual(json_decode(json_encode($written, JSON_THROW_ON_ERROR), false));
});
