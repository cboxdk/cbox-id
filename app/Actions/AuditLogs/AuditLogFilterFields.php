<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Platform\Actions\Input\Field;

/**
 * The filters a list of audit events and an export of one both take — one definition, so
 * "export what I am looking at" is the same request with a different verb.
 */
final class AuditLogFilterFields
{
    /**
     * @return list<Field>
     */
    public static function all(): array
    {
        return [
            Field::string('organization_id')->max(64)->describe('Only this organization\'s events. Required in effect for an organization\'s own administrator, whose events are the only ones they see.'),
            Field::list('actions', Field::string('action')->max(190))->max(50)->describe('Only these actions, e.g. `invoice.voided`.'),
            Field::string('actor_id')->max(190)->describe('Only events this actor caused.'),
            Field::string('target_id')->max(190)->describe('Only events naming this target.'),
            Field::string('range_start')->format('date-time')->describe('Only events that occurred at or after this time.'),
            Field::string('range_end')->format('date-time')->describe('Only events that occurred before this time.'),
        ];
    }
}
