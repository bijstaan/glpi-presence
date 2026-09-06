<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipresence;

use CommonDBTM;
use CronTask;

/**
 * Sweeps expired presence rows and idle claims.
 *
 * The heartbeat endpoint already sweeps opportunistically, but only while
 * someone has a ticket open. This is the backstop for the case that actually
 * matters: everyone goes home, and the last rows written before they left
 * would otherwise sit in the table until the next person opens that ticket.
 */
final class GarbageCollector extends CommonDBTM
{
    public static function getTypeName($nb = 0)
    {
        return __('Presence cleanup');
    }

    /** @param string $name */
    public static function cronInfo($name): array
    {
        return ['description' => __('Expire stale technician presence and idle claims')];
    }

    public static function cronSweep(CronTask $task): int
    {
        $presence = Presence::sweep();
        $claims   = Claim::sweepIdle();

        $task->setVolume($presence + $claims);
        $task->log(sprintf('Removed %d presence rows and %d idle claims', $presence, $claims));

        // >0 tells GLPI the run did something; 0 means "ran, nothing to do".
        return ($presence + $claims) > 0 ? 1 : 0;
    }
}
