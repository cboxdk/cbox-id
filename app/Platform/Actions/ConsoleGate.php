<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Console\ConsoleScope;

/**
 * Which of the console's gates a person must pass to run an action from the console — the
 * same {@see ConsoleScope} assertion the page used before the action existed.
 */
enum ConsoleGate
{
    /** The environment console only: the thing belongs to the environment. */
    case EnvironmentAdmin;

    /** Any console where the person administers what they are looking at. */
    case Administer;
}
