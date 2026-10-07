<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use Cbox\Id\OAuthServer\Contracts\ActionApprovals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Which credential asked for an approval, so only that credential can poll or spend it.
 * The id IS the framework's CIBA request id ({@see ActionApprovals}),
 * which lives in the platform root.
 *
 * It also keeps what the approval is FOR — the environment whose console lists it, the
 * binding code, and a redacted copy of the input ({@see ApprovalInput}) — so the person
 * can read the request before answering it, on the console as well as on their phone.
 *
 * @property string $id
 * @property string $principal
 * @property string $action
 * @property string|null $environment_id
 * @property string|null $binding_code
 * @property array<string, mixed>|null $input
 * @property Carbon|null $created_at
 */
final class ActionApprovalRequest extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'action_approval_requests';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** The environment key that raised it, or null when a workspace key did. */
    public function environmentKeyId(): ?string
    {
        return str_starts_with($this->principal, 'environment_key:')
            ? substr($this->principal, strlen('environment_key:'))
            : null;
    }
}
