<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipresence;

use Config;

/**
 * Plugin settings, with defaults.
 *
 * The timing values are related and easy to get wrong, so they are validated
 * together rather than trusted from the config table: a presence TTL shorter
 * than the background heartbeat would make every backgrounded tab flicker in
 * and out of the participant list.
 */
final class Settings
{
    public const DEFAULTS = [
        // Which ITIL types get a presence bar.
        'itemtypes'         => 'Ticket,Change,Problem',
        // Heartbeat cadence, seconds, while the tab is focused / backgrounded.
        // Must stay below typing_ttl — see reconcile().
        'heartbeat_focused' => 8,
        'heartbeat_hidden'  => 45,
        // A participant is considered gone this long after their last beat.
        'presence_ttl'      => 90,
        // "is typing" decays this fast without a refreshing beat.
        'typing_ttl'        => 12,
        // Soft claim: offer the "I'm working this" action at all.
        'claim_enabled'     => 1,
        // A claim is dropped after this much inactivity from its holder.
        'claim_idle_ttl'    => 1800,
    ];

    /** @return array<string,int|string> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPIPRESENCE_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            if ($value === null || $value === '') {
                $out[$key] = $default;
                continue;
            }
            $out[$key] = is_int($default) ? (int) $value : (string) $value;
        }

        return self::reconcile($out);
    }

    public static function get(string $key): int|string
    {
        return self::all()[$key] ?? self::DEFAULTS[$key];
    }

    /**
     * Keep the timings mutually consistent. A stored config that violates
     * these is corrected in memory rather than rejected, so a bad value can
     * never take presence down — it just behaves like the nearest sane one.
     */
    private static function reconcile(array $s): array
    {
        $s['heartbeat_focused'] = max(5, min(300, (int) $s['heartbeat_focused']));
        $s['heartbeat_hidden']  = max($s['heartbeat_focused'], min(600, (int) $s['heartbeat_hidden']));

        // Must outlive a missed background beat, or backgrounded tabs flicker.
        $s['presence_ttl'] = max($s['heartbeat_hidden'] * 2, (int) $s['presence_ttl']);

        // "is typing" must outlive the poll interval by a clear margin, or the
        // signal can fall between two polls: a technician types a whole
        // sentence, their typing flag decays, and the peer polling every N
        // seconds never once observes it. Sampling has to be faster than the
        // thing being sampled, so a slow heartbeat drags this up rather than
        // being allowed to miss.
        $s['typing_ttl'] = max(5, (int) $s['typing_ttl']);
        $s['typing_ttl'] = max($s['typing_ttl'], (int) ceil($s['heartbeat_focused'] * 1.5));

        $s['claim_idle_ttl'] = max(60, (int) $s['claim_idle_ttl']);
        $s['claim_enabled']  = ((int) $s['claim_enabled']) === 1 ? 1 : 0;

        return $s;
    }

    /**
     * Itemtypes presence applies to, filtered to ones that actually exist.
     * @return string[]
     */
    public static function itemtypes(): array
    {
        $raw = (string) self::get('itemtypes');
        $out = [];
        foreach (explode(',', $raw) as $type) {
            $type = trim($type);
            if ($type !== '' && class_exists($type)) {
                $out[] = $type;
            }
        }
        return $out;
    }

    public static function appliesTo(string $itemtype): bool
    {
        return in_array($itemtype, self::itemtypes(), true);
    }

    public static function save(array $input): void
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $input[$key];
            }
        }
        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_GLPIPRESENCE_CONFIG_CONTEXT, $values);
        }
    }
}
