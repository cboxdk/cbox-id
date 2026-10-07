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
 * @property string $id
 * @property string $principal
 * @property string $action
 * @property Carbon|null $created_at
 */
final class ActionApprovalRequest extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'action_approval_requests';

    protected $guarded = [];
}
