<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Approvals\ApprovalInput;
use App\Platform\Agents\ActionApprovalEntry;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;

/**
 * One held agent action, as the Approvals inbox draws it: who asked, what for, on what,
 * with which arguments — and whether the person reading may answer it.
 *
 * The arguments are the REDACTED copy the gate kept ({@see ApprovalInput}), flattened to
 * one readable line each. A secret shows as removed, never as itself.
 */
final readonly class ActionApprovalRowProps implements Prop
{
    /**
     * @param  list<array{field: string, value: string, redacted: bool}>  $arguments
     */
    public function __construct(
        public ActionApprovalEntry $entry,
        public string $agent,
        public ?string $agentId,
        public ?ActionDefinition $action,
        public ?string $target,
        public array $arguments,
        public string $approver,
        public bool $mine,
        public bool $needsSudo,
        public ?string $approveHref,
        public ?string $denyHref,
    ) {}

    /**
     * The action's input, minus what the target already says, as `field: value` lines.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $pathFields
     * @return list<array{field: string, value: string, redacted: bool}>
     */
    public static function arguments(array $input, array $pathFields): array
    {
        $lines = [];

        foreach ($input as $field => $value) {
            if (in_array($field, $pathFields, true) || $value === null) {
                continue;
            }

            $lines[] = [
                'field' => $field,
                'value' => self::line($value),
                'redacted' => $value === ApprovalInput::REDACTED,
            ];
        }

        return $lines;
    }

    /** One value as a reader wants it: `yes`, a plain list, or compact JSON for a shape. */
    private static function line(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value) && array_is_list($value)) {
            $items = [];

            foreach ($value as $item) {
                if (! is_scalar($item)) {
                    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                $items[] = is_bool($item) ? ($item ? 'yes' : 'no') : (string) $item;
            }

            return implode(', ', $items);
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function statusLabel(ActionApprovalStatus $status): string
    {
        return match ($status) {
            ActionApprovalStatus::Pending => 'Waiting',
            ActionApprovalStatus::Approved => 'Approved',
            ActionApprovalStatus::Denied => 'Denied',
            ActionApprovalStatus::Expired => 'Expired',
            ActionApprovalStatus::Consumed => 'Approved and run',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->entry->request->id,
            'agent' => $this->agent,
            'agentId' => $this->agentId,
            'action' => [
                'name' => $this->entry->request->action,
                'summary' => $this->action?->summary,
                'danger' => $this->action?->danger->value,
            ],
            'target' => $this->target,
            'arguments' => $this->arguments,
            'status' => $this->entry->status->value,
            'statusLabel' => self::statusLabel($this->entry->status),
            'bindingCode' => $this->entry->request->binding_code,
            'requestedAt' => $this->entry->request->created_at?->toIso8601String(),
            'expiresAt' => $this->entry->expiresAt->toIso8601String(),
            'approver' => $this->approver,
            'mine' => $this->mine,
            'needsSudo' => $this->needsSudo,
            'approveHref' => $this->approveHref,
            'denyHref' => $this->denyHref,
        ];
    }
}
