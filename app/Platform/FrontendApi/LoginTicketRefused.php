<?php

declare(strict_types=1);

namespace App\Platform\FrontendApi;

use RuntimeException;

/**
 * A login ticket was asked for somebody who may not have one ({@see LoginTickets::refuses()}).
 *
 * The frontend API's controllers ask first and never reach this; it is what stops a new
 * caller that forgot to.
 */
final class LoginTicketRefused extends RuntimeException {}
