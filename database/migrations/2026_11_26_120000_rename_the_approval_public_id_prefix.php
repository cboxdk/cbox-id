<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An approval's public id is `idapr_01j…`, no longer `apr_01j…`.
 *
 * Contested prefixes went to the app that owns the domain on 2026-11-26; `apr` has no owner,
 * so Cbox ID took an app-tagged one. Ids are stored bare — every key and foreign key holds
 * the ULID — so no column changes. The prefix lives only in what was written in the public
 * form: the payloads of deliveries to endpoints that receive public ids (`id_format`
 * `prefixed`), kept as signed and sent, and sent again as they are on a retry or a redelivery.
 * Those are rewritten here, so a receiver never gets the retired prefix again.
 *
 * Only where Cbox ID wrote an approval's id: an `approval_id` field, an `approval_ids` list,
 * and the `id` of an `object: "approval"`. A customer's own data (metadata, properties,
 * claims, actor, targets, …) keeps whatever it said, as it did when it was encoded.
 *
 * Self-contained on purpose: the registry in PublicId will lose `apr` when the overlap ends
 * (2027-01-15), and this must still mean the same then. `apr_` is read on input until then.
 */
return new class extends Migration
{
    /** Keys whose contents are not Cbox ID's to rewrite, as PublicIdTree::OPAQUE had them. */
    private const array OPAQUE = ['metadata', 'custom_attributes', 'properties', 'claims', 'actor', 'targets', 'context', 'settings', 'raw'];

    private const string ULID = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

    public function up(): void
    {
        $this->rename('apr', 'idapr');
    }

    public function down(): void
    {
        $this->rename('idapr', 'apr');
    }

    private function rename(string $from, string $to): void
    {
        $text = DB::connection()->getDriverName() === 'mysql' ? 'CAST(payload AS CHAR)' : 'CAST(payload AS TEXT)';

        DB::table('webhook_deliveries')
            ->select(['id', 'payload'])
            // `!` escapes the `_`: SQLite's LIKE has no escape character unless one is named.
            ->whereRaw("{$text} LIKE ? ESCAPE '!'", ['%"'.$from.'!_%'])
            ->chunkById(500, function ($rows) use ($from, $to): void {
                foreach ($rows as $row) {
                    if (! is_string($row->payload)) {
                        continue;
                    }

                    $payload = json_decode($row->payload, false, 512, JSON_THROW_ON_ERROR);
                    $renamed = $this->walk($payload, $from, $to);

                    if ($renamed != $payload) {
                        DB::table('webhook_deliveries')->where('id', $row->id)->update(['payload' => json_encode($renamed, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
    }

    private function walk(mixed $node, string $from, string $to): mixed
    {
        if (! is_array($node) && ! $node instanceof stdClass) {
            return $node;
        }

        $copy = is_array($node) ? $node : clone $node;
        $isApproval = $copy instanceof stdClass && ($copy->object ?? null) === 'approval';

        foreach (is_array($copy) ? $copy : get_object_vars($copy) as $key => $value) {
            if (is_string($key) && in_array($key, self::OPAQUE, true)) {
                continue;
            }

            $renamed = match (true) {
                $key === 'approval_id', $key === 'id' && $isApproval => $this->one($value, $from, $to),
                $key === 'approval_ids' && is_array($value) => array_map(fn (mixed $one): mixed => $this->one($one, $from, $to), $value),
                default => $this->walk($value, $from, $to),
            };

            if (is_array($copy)) {
                $copy[$key] = $renamed;
            } else {
                $copy->{$key} = $renamed;
            }
        }

        return $copy;
    }

    private function one(mixed $value, string $from, string $to): mixed
    {
        return is_string($value) && preg_match('/\A'.$from.'_('.self::ULID.')\z/', $value, $match) === 1 ? $to.'_'.$match[1] : $value;
    }
};
