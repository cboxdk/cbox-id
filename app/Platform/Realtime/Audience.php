<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

/**
 * Who is told about a change: the environment it happened in, the organizations it
 * belongs to and the people it is about. Each becomes one channel.
 */
final readonly class Audience
{
    /**
     * @param  list<string>  $organizationIds
     * @param  list<string>  $subjectIds
     */
    public function __construct(
        public ?string $environmentId = null,
        public array $organizationIds = [],
        public array $subjectIds = [],
    ) {}

    /** @return list<string> */
    public function channels(): array
    {
        $channels = [];

        if (self::usable($this->environmentId)) {
            $channels[] = Channels::environment($this->environmentId);
        }

        foreach ($this->organizationIds as $id) {
            if (self::usable($id)) {
                $channels[] = Channels::organization($id);
            }
        }

        foreach ($this->subjectIds as $id) {
            if (self::usable($id)) {
                $channels[] = Channels::subject($id);
            }
        }

        return array_values(array_unique($channels));
    }

    /** @phpstan-assert-if-true non-empty-string $id */
    private static function usable(?string $id): bool
    {
        return is_string($id) && $id !== '' && Channels::valid($id);
    }
}
