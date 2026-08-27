<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Help\DocsLinks;
use App\Platform\Help\HelpTopic;

/**
 * THE CONSOLE'S EXPLANATION LAYER, resolved for one page.
 *
 * The copy lives in {@see HelpTopic} rather than in the view, and it stays there: it has
 * to be identical wherever the same concept surfaces — a page header, an empty state, the
 * setup checklist all explain "single sign-on" in the same words — and it has to stay
 * honest about which concepts we have actually written a guide for. `href` is null where
 * none exists, and the UI omits the link rather than shipping a 404.
 *
 * Sending the resolved text rather than the enum case is deliberate. A React page holding
 * a topic KEY would need its own copy of the strings to render, which is the second
 * source of truth this enum exists to prevent.
 */
final readonly class HelpProps implements Prop
{
    public function __construct(
        public string $topic,
        public string $title,
        public string $summary,
        public ?string $href,
    ) {}

    public static function for(HelpTopic $topic): self
    {
        return new self(
            topic: $topic->value,
            title: $topic->title(),
            summary: $topic->summary(),
            href: app(DocsLinks::class)->url($topic),
        );
    }

    /**
     * @return array{topic: string, title: string, summary: string, href: string|null}
     */
    public function toArray(): array
    {
        return [
            'topic' => $this->topic,
            'title' => $this->title,
            'summary' => $this->summary,
            'href' => $this->href,
        ];
    }
}
