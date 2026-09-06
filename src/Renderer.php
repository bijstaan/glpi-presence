<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipresence;

use CommonDBTM;
use Plugin;
use Session;

/**
 * Emits the presence bar's mount point onto an ITIL form.
 *
 * Renders empty and hidden. Everything visible is drawn client-side from the
 * heartbeat response, so there is exactly one code path producing the bar —
 * a server-rendered first paint would have to be kept byte-identical to the
 * JS render or the bar would visibly rewrite itself on first poll.
 */
final class Renderer
{
    public static function render(CommonDBTM $item): void
    {
        if (!self::applies($item)) {
            return;
        }

        $config = [
            'endpoint'          => Plugin::getWebDir('glpipresence', true) . '/ajax/presence.php',
            'itemtype'          => $item->getType(),
            'items_id'          => (int) $item->getID(),
            'users_id'          => (int) Session::getLoginUserID(),
            'csrf'              => Session::getNewCSRFToken(),
            'can_update'        => $item->canUpdateItem(),
            'claim_enabled'     => (bool) Settings::get('claim_enabled'),
            'heartbeat_focused' => (int) Settings::get('heartbeat_focused'),
            'heartbeat_hidden'  => (int) Settings::get('heartbeat_hidden'),
        ];

        $json = htmlspecialchars(
            json_encode($config, JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        // The i18n strings are passed through too: the JS renders every visible
        // string, and shipping them from PHP keeps the plugin translatable
        // without a second JS-side catalogue.
        $labels = htmlspecialchars(
            json_encode([
                'viewing'      => __('is viewing'),
                'typing'       => __('is typing…'),
                'typing_kind'  => __('is writing a %s…'),
                'working'      => __('is working this'),
                'you'          => __('you'),
                'take_over'    => __('Take over'),
                'work_along'   => __('Work alongside'),
                'claim'        => __('I am working this'),
                'release'      => __('Release'),
                'alone'        => __('You are the only one here'),
                'taken_over'   => __('You took over this ticket'),
                'confirm_take' => __('Take over from %s? They will keep working unless you tell them.'),
                'followup'     => __('reply'),
                'task'         => __('task'),
                'solution'     => __('solution'),
            ], JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        echo '<div data-glpipresence-root style="display:none"'
            . ' data-config="' . $json . '"'
            . ' data-labels="' . $labels . '"></div>';
    }

    /**
     * Presence is a technician-facing signal. It is not rendered in the
     * helpdesk interface: telling a requester that "Sam is typing" leaks
     * internal workflow, and their own presence is not something the
     * technicians asked to track.
     */
    private static function applies(CommonDBTM $item): bool
    {
        if (Session::getCurrentInterface() !== 'central') {
            return false;
        }
        if ((int) Session::getLoginUserID() <= 0) {
            return false;
        }
        if (!Settings::appliesTo($item->getType())) {
            return false;
        }
        return $item->canViewItem();
    }
}
