<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Models\AuditLogs\AuditLogSettings;

/**
 * The current environment's audit-log settings: how long events are kept, and whether an
 * action without a schema is refused. The deployment's defaults
 * (`cbox-id.audit_logs.retention_days`, not strict) until the environment says otherwise.
 *
 * Read in the environment the request runs in — {@see AuditLogSettings} is environment-owned,
 * so there is no id to pass and none to get wrong.
 */
final class AuditLogPolicy
{
    public const int MIN_RETENTION_DAYS = 1;

    public const int MAX_RETENTION_DAYS = 3650;

    public static function defaultRetentionDays(): int
    {
        $days = config('cbox-id.audit_logs.retention_days', 365);

        return is_int($days) && $days >= self::MIN_RETENTION_DAYS ? min($days, self::MAX_RETENTION_DAYS) : 365;
    }

    public function retentionDays(): int
    {
        return $this->stored()->retention_days ?? self::defaultRetentionDays();
    }

    public function strict(): bool
    {
        return $this->stored()->strict_schemas ?? false;
    }

    /**
     * Change either setting; returns what changed, as `field => [from, to]`.
     *
     * @return array<string, array{from: int|bool, to: int|bool}>
     */
    public function update(?int $retentionDays, ?bool $strict): array
    {
        $before = ['retention_days' => $this->retentionDays(), 'strict_schemas' => $this->strict()];
        $after = [
            'retention_days' => $retentionDays ?? $before['retention_days'],
            'strict_schemas' => $strict ?? $before['strict_schemas'],
        ];

        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['from' => $before[$field], 'to' => $value];
            }
        }

        if ($changes === []) {
            return [];
        }

        $settings = $this->stored() ?? new AuditLogSettings;
        $settings->forceFill($after)->save();

        return $changes;
    }

    private function stored(): ?AuditLogSettings
    {
        return AuditLogSettings::query()->first();
    }
}
