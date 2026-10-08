<?php

declare(strict_types=1);

namespace App\Platform\Connect;

/**
 * One copyable block of a quickstart: a command, or code that goes in `$file`.
 *
 * `$code` is verbatim a fenced block of the framework's quickstart page — or, for a one-line
 * run command, the page's inline code ({@see QuickstartSources}).
 */
final readonly class QuickstartBlock
{
    public function __construct(
        public string $code,
        /** Where it goes, as a path; null for a command to run. */
        public ?string $file = null,
        /** One sentence the block needs that is not code — "then add cbox_id to fillable". */
        public ?string $note = null,
    ) {}

    /**
     * @return array{code: string, file: string|null, note: string|null}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'file' => $this->file, 'note' => $this->note];
    }
}
