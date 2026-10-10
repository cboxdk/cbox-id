<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarAction;

/**
 * What Radar decided about one attempt, and why.
 *
 * `rule` names what decided it — `deny_list:ip`, `allow_list:email`, `rule:<id>`,
 * `builtin:credential_stuffing`, or null when nothing matched and the attempt was allowed by
 * default. `triggered` is every rule that WOULD have fired, the deciding one included, so the
 * explorer can show that an allow rule overrode a built-in block. `reasons` are the sentences.
 */
final readonly class RadarVerdict
{
    /**
     * @param  list<string>  $triggered
     * @param  list<string>  $reasons
     */
    public function __construct(
        public RadarAction $action,
        public ?string $rule = null,
        public ?string $ruleName = null,
        public array $triggered = [],
        public array $reasons = [],
    ) {}

    public static function allow(): self
    {
        return new self(RadarAction::Allow);
    }
}
