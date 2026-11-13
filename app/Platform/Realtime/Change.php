<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

/**
 * One record that changed, as a page is told about it: the topic it belongs to (`devices`),
 * the kind of record (`device`), its id and what happened (`created`).
 *
 * Deliberately nothing else. No name, address, label, token or value travels — a page that
 * cares re-reads the record through its own gates.
 */
final readonly class Change
{
    public function __construct(
        public string $topic,
        public string $type,
        public ?string $id,
        public string $action,
    ) {}

    /** @return array{topic: string, type: string, id: string|null, action: string} */
    public function toArray(): array
    {
        return ['topic' => $this->topic, 'type' => $this->type, 'id' => $this->id, 'action' => $this->action];
    }
}
