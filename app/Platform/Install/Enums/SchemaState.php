<?php

declare(strict_types=1);

namespace App\Platform\Install\Enums;

/**
 * Whether the database can answer the installer's questions.
 *
 * Three states rather than a boolean, because a failed query cannot tell "this
 * deployment has not migrated yet" from "the database is down" — and those call for
 * opposite behaviour. Collapsing them either 500s every page on a fresh container, or
 * turns a database outage on a live platform into an open setup screen.
 */
enum SchemaState
{
    /** The tables exist; the occupancy questions can be asked. */
    case Ready;

    /** The database answered, but the schema is not there — migrations have not run. */
    case Missing;

    /** The database could not be asked at all. Nothing is known, so nothing is assumed. */
    case Unreachable;
}
