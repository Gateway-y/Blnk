<?php

declare(strict_types=1);

namespace Blnk\Internal\HotPairs;

/**
 * Port of the Go `hotpairs.State` string type and its constants. States are
 * plain strings in PHP; the constants keep their Go names.
 */
final class State
{
    public const StateNormal = 'normal';
    public const StatePromoting = 'promoting';
    public const StateHot = 'hot';
    public const StateCoolingDown = 'cooling_down';

    private function __construct()
    {
    }
}
