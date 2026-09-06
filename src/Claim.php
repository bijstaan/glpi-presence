<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipresence;

use DBmysql;
use User;

/**
 * The soft claim: "I'm working this."
 *
 * Voluntary and advisory. A claim never prevents anyone from editing — a
 * second technician sees who holds it and chooses to take over or work
 * alongside. That is the whole difference from GLPI's ObjectLock, which
 * demotes everyone else to a read-only profile and can wedge a ticket when
 * the holder's browser dies.
 *
 * Held per item, not per session, so it survives a page refresh. It expires
 * on *inactivity* rather than on disconnect, since a technician who steps
 * away for two minutes has not stopped working the ticket.
 */
final class Claim
{
    public const TABLE = 'glpi_plugin_glpipresence_claims';

    /**
     * The active claim on an item, or null.
     *
     * @return array{users_id:int,name:string,since:int,idle:int}|null
     */
    public static function current(string $itemtype, int $items_id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!Settings::get('claim_enabled')) {
            return null;
        }

        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            $idle = time() - (int) $row['last_activity'];
            if ($idle > (int) Settings::get('claim_idle_ttl')) {
                // Expired but not yet swept — treat as absent so the UI never
                // shows a claim the next action would silently drop.
                return null;
            }

            $user = new User();
            $name = $user->getFromDB((int) $row['users_id'])
                ? (string) $user->getFriendlyName()
                : __('Unknown user');

            return [
                'users_id' => (int) $row['users_id'],
                'name'     => $name,
                'since'    => (int) $row['date_creation'],
                'idle'     => max(0, $idle),
            ];
        }

        return null;
    }

    /**
     * Claim an item for a user.
     *
     * Taking over an existing claim is allowed but must be explicit: a plain
     * claim against a held item fails, so the UI cannot steal one by accident
     * (e.g. a double-submit racing another technician's claim).
     *
     * @return bool False when held by someone else and $take_over is false.
     */
    public static function claim(
        string $itemtype,
        int $items_id,
        int $users_id,
        bool $take_over = false
    ): bool {
        /** @var DBmysql $DB */
        global $DB;

        if (!Settings::get('claim_enabled')) {
            return false;
        }

        $now     = time();
        $current = self::current($itemtype, $items_id);

        if ($current !== null && $current['users_id'] !== $users_id && !$take_over) {
            return false;
        }

        if ($current !== null && $current['users_id'] === $users_id) {
            self::touch($itemtype, $items_id, $users_id);
            return true;
        }

        $DB->updateOrInsert(
            self::TABLE,
            [
                'users_id'      => $users_id,
                'last_activity' => $now,
                'date_creation' => $now,
            ],
            ['itemtype' => $itemtype, 'items_id' => $items_id]
        );

        return true;
    }

    /** Release a claim. Only the holder may release their own. */
    public static function release(string $itemtype, int $items_id, int $users_id): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'users_id' => $users_id,
        ]);

        return $DB->affectedRows() > 0;
    }

    /** Keep a live claim alive; called from the holder's heartbeat. */
    public static function touch(string $itemtype, int $items_id, int $users_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->update(
            self::TABLE,
            ['last_activity' => time()],
            ['itemtype' => $itemtype, 'items_id' => $items_id, 'users_id' => $users_id]
        );
    }

    /** Drop claims whose holder has gone quiet past the idle TTL. */
    public static function sweepIdle(): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, [
            'last_activity' => ['<', time() - (int) Settings::get('claim_idle_ttl')],
        ]);

        return $DB->affectedRows();
    }
}
