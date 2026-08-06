<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use RuntimeException;

/**
 * A presented enrolment code was refused.
 *
 * Every reason — expired, forged, already spent, someone else's — surfaces to the API
 * caller as one 422 with a deliberately unspecific message. Telling a caller WHICH check
 * failed would let them probe: "already used" confirms a code existed, "different
 * account" confirms which subject it belonged to. The console-side reasons stay in the
 * exception for the log, not the response.
 */
final class EnrolmentCodeRejected extends RuntimeException {}
