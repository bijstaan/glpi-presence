<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipresence;

use DBmysql;
use User;

/**
 * Who is currently on an ITIL object.
 *
 * One row per *browser tab*, not per user: a technician with the same ticket
 * open twice would otherwise have their two tabs overwrite each other's
 * heartbeat and typing state. Tabs are collapsed back into one entry per user
 * at read time.
 *
 * All times are unix integers rather than SQL TIMESTAMPs so that expiry
 * arithmetic happens in one clock (PHP's) — comparing a PHP `time()` against
 * a MySQL `CURRENT_TIMESTAMP` silently breaks when the two disagree on
 * timezone, which is exactly the kind of bug that would make presence
 * intermittently wrong rather than obviously broken.
 */
final class Presence
{
    public const TABLE = 'glpi_plugin_glpipresence_presence';

    /** Record (or refresh) this session's presence on an item. */
    public static function heartbeat(
        string $itemtype,
        int $items_id,
        string $session_key,
        int $users_id,
        bool $typing,
        ?string $typing_kind
    ): void {
        /** @var DBmysql $DB */
        global $DB;

        $now      = time();
        $settings = Settings::all();

        $fields = [
            'users_id'  => $users_id,
            'last_seen' => $now,
        ];

        // Typing is stored as a deadline, not a flag, so it decays on its own.
        // A client that dies mid-sentence stops looking like it is typing
        // without needing to send a "stopped" message it may never get to send.
        if ($typing) {
            $fields['typing_until'] = $now + (int) $settings['typing_ttl'];
            $fields['typing_kind']  = $typing_kind;
        } else {
            $fields['typing_until'] = 0;
            $fields['typing_kind']  = null;
        }

        $where = [
            'itemtype'    => $itemtype,
            'items_id'    => $items_id,
            'session_key' => $session_key,
        ];

        // updateOrInsert merges $where into the insert, so a new row gets its
        // identity columns for free.
        $DB->updateOrInsert(self::TABLE, $fields + ['date_creation' => $now], $where);
    }

    /** Drop this session's presence (tab closed / navigated away). */
    public static function leave(string $itemtype, int $items_id, string $session_key): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, [
            'itemtype'    => $itemtype,
            'items_id'    => $items_id,
            'session_key' => $session_key,
        ]);
    }

    /**
     * Live participants on an item, one entry per user.
     *
     * @return array<int,array{users_id:int,name:string,initials:string,typing:bool,typing_kind:?string,since:int,sessions:int}>
     */
    public static function participants(string $itemtype, int $items_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $now    = time();
        $cutoff = $now - (int) Settings::get('presence_ttl');

        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'itemtype'  => $itemtype,
                'items_id'  => $items_id,
                'last_seen' => ['>', $cutoff],
            ],
            'ORDER' => 'date_creation ASC',
        ]);

        $byUser = [];
        foreach ($rows as $row) {
            $uid = (int) $row['users_id'];
            if ($uid <= 0) {
                continue;
            }

            $typing = ((int) $row['typing_until']) > $now;

            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'users_id'    => $uid,
                    'name'        => self::displayName($uid),
                    'initials'    => '',
                    'typing'      => false,
                    'typing_kind' => null,
                    'since'       => (int) $row['date_creation'],
                    'sessions'    => 0,
                ];
                $byUser[$uid]['initials'] = self::initials($byUser[$uid]['name']);
            }

            $byUser[$uid]['sessions']++;
            // Across a user's tabs, typing anywhere means typing.
            if ($typing && !$byUser[$uid]['typing']) {
                $byUser[$uid]['typing']      = true;
                $byUser[$uid]['typing_kind'] = $row['typing_kind'] !== null
                    ? (string) $row['typing_kind']
                    : null;
            }
            $byUser[$uid]['since'] = min($byUser[$uid]['since'], (int) $row['date_creation']);
        }

        return array_values($byUser);
    }

    /** Is this user currently present on the item (any tab)? */
    public static function isPresent(string $itemtype, int $items_id, int $users_id): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        return countElementsInTable(self::TABLE, [
            'itemtype'  => $itemtype,
            'items_id'  => $items_id,
            'users_id'  => $users_id,
            'last_seen' => ['>', time() - (int) Settings::get('presence_ttl')],
        ]) > 0;
    }

    /**
     * Delete presence rows nobody is behind any more.
     *
     * Run both from cron and opportunistically from the heartbeat endpoint —
     * the table must stay correct on installs where cron is not configured,
     * and a stale "someone else is here" is the one failure mode that makes
     * this feature worse than not having it.
     */
    public static function sweep(): int
    {
        /** @var DBmysql $DB */
        global $DB;

        // Sweep well past the display cutoff: rows between the two are already
        // invisible to readers, and keeping them briefly lets a tab that was
        // suspended (laptop lid, phone lock) resume its own row instead of
        // reappearing as a brand-new participant.
        $cutoff = time() - ((int) Settings::get('presence_ttl') * 4);

        $DB->delete(self::TABLE, ['last_seen' => ['<', $cutoff]]);
        return $DB->affectedRows();
    }

    private static function displayName(int $users_id): string
    {
        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return __('Unknown user');
        }
        return (string) $user->getFriendlyName();
    }

    /** Two-letter monogram for the avatar chip. */
    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        $first = mb_substr($parts[0], 0, 1);
        $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}
