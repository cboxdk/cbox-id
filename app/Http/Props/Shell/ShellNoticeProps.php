<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;
use App\Platform\Console\WorkspaceAltitude;

/**
 * A sentence the shell puts above a page that is reachable but not in the rail, saying
 * what the page is and where the reader probably meant to be.
 *
 * @see WorkspaceAltitude — the one case today: a workspace console
 *      reaching one of its organization's end-user administration pages by URL.
 */
final readonly class ShellNoticeProps implements Prop
{
    public function __construct(
        public string $message,
        public string $href,
        public string $label,
    ) {}

    /**
     * @return array{message: string, href: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'href' => $this->href,
            'label' => $this->label,
        ];
    }
}
