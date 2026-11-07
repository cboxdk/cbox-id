<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use InvalidArgumentException;

/** Reference to immutable meaning already registered by the control plane. */
readonly class IntervalSeries
{
    public function __construct(public string $sourceId, public string $id, public int $revision)
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,189}$/D', $sourceId) || ! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $id) || $revision < 1 || $revision > 1000000) {
            throw new InvalidArgumentException('Invalid interval series reference.');
        }
    }

    public function path(): string
    {
        return '/v2/sources/'.rawurlencode($this->sourceId).'/interval-series/'.rawurlencode($this->id).'/revisions/'.$this->revision.'/intervals';
    }
}
