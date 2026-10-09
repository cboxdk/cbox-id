<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarField;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\Radar\Enums\RadarMethod;
use Cbox\Risk\Enums\Outcome;

/**
 * Everything Radar knows about one attempt, by {@see RadarField} — what the rules are
 * evaluated on.
 *
 * Three of the facts are identifiers (the IP, the address, the user agent). They exist here,
 * in memory, so a rule can name them; {@see self::recordable()} is what is WRITTEN, and it
 * leaves them out. A null fact is unknown.
 */
final readonly class RadarFacts
{
    /** Facts a rule may test but that are never written down. */
    private const array TRANSIENT = [RadarField::Ip, RadarField::Email, RadarField::UserAgent];

    /**
     * @param  array<string, string|int|float|bool|null>  $values  keyed by {@see RadarField} value
     */
    public function __construct(
        public RadarFlow $flow,
        public RadarMethod $method,
        public array $values,
        public ?string $deviceHash = null,
        public ?Outcome $riskOutcome = null,
    ) {}

    public function get(RadarField $field): string|int|float|bool|null
    {
        return $this->values[$field->value] ?? null;
    }

    public function bool(RadarField $field): bool
    {
        return $this->get($field) === true;
    }

    public function number(RadarField $field): ?float
    {
        $value = $this->get($field);

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * The facts as the decision trail keeps them: no IP, no address, no user agent.
     *
     * @return array<string, string|int|float|bool>
     */
    public function recordable(): array
    {
        $kept = [];

        foreach ($this->values as $key => $value) {
            $field = RadarField::tryFrom($key);

            if ($value === null || $field === null || in_array($field, self::TRANSIENT, true)) {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }
}
