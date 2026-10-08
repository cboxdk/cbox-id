<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\AuditLogs\AuditLogFilters;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * WHOSE audit events a reader may see — asked once for every read and export.
 *
 *  - the environment's own authority (a management key, the environment console): any
 *    organization's, or every organization's at once when it names none;
 *  - a confined reader (an organization's administrator on their console, a token one of
 *    them signed in for): their own organization's and nothing else. Naming another is
 *    refused, not quietly answered with their own — a caller who asked for somebody
 *    else's events should learn they cannot have them, not receive a list that looks like
 *    the answer.
 */
final class AuditLogReach
{
    /**
     * The organization this read is held to: the caller's own when confined, else the one
     * named (checked to be in this environment), else null for the whole environment.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function organization(ActionContext $context): ?string
    {
        $asked = $context->nullableString('organization_id');
        $confinedTo = $context->principal->confinedToOrganization();

        return OrganizationTarget::check($context, $confinedTo !== null ? ($asked ?? $confinedTo) : $asked);
    }

    /**
     * The filters an action's input asks for, held to the reader's reach — with a time that
     * does not parse refused rather than dropped, which would silently widen the list.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public static function filters(ActionContext $context): AuditLogFilters
    {
        foreach (['range_start', 'range_end'] as $field) {
            $value = $context->nullableString($field);

            if ($value !== null && ! self::parses($value)) {
                throw ActionRefused::because('invalid_range', "{$field} must be an ISO 8601 date-time.", $field);
            }
        }

        $filters = AuditLogFilters::from($context->input, self::organization($context));

        if ($filters->rangeStart !== null && $filters->rangeEnd !== null && ! $filters->rangeStart->isBefore($filters->rangeEnd)) {
            throw ActionRefused::because('invalid_range', 'range_start must be before range_end.', 'range_start');
        }

        return $filters;
    }

    private static function parses(string $value): bool
    {
        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
