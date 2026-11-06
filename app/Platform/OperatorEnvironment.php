<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\Facades\URL;

/** Navigation context for this request only. No browser tab can retarget another. */
final class OperatorEnvironment
{
    /** Kept for clearing legacy sessions; never read as authority. */
    public const SESSION_KEY = 'cbox.operator_environment';

    public function slug(): ?string
    {
        $slug = request()->attributes->get('operator_target');

        return is_string($slug) && $slug !== '' && $slug !== 'root' ? $slug : null;
    }

    public function pointAt(string $slug): void
    {
        request()->attributes->set('operator_target', $slug);
        URL::defaults(['targetEnvironment' => $slug]);
    }

    public function release(): void
    {
        request()->attributes->remove('operator_target');
        URL::defaults(['targetEnvironment' => 'root']);
        session()->forget(self::SESSION_KEY);
    }
}
