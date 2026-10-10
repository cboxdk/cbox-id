<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use InvalidArgumentException;

/**
 * A rule, a list entry or a built-in setting that cannot be saved — with the sentence that
 * says why, written for whoever typed it.
 */
final class RadarRuleInvalid extends InvalidArgumentException {}
