<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI Presence — collision detection & live presence for technicians.
 *
 * Answers, on the ticket a technician just opened: is anyone else already
 * here, are they typing right now, and has someone claimed the work?
 *
 * Deliberately *soft*. GLPI core already ships ObjectLock, a single-holder
 * pessimistic lock that forces everyone else into a read-only profile; it
 * cannot express "three people are here", has no viewing-vs-typing notion,
 * and strands a ticket for hours when a browser dies. This plugin never
 * blocks anyone — it informs, and offers a voluntary claim that auto-expires
 * on idle.
 *
 * Central interface only: presence is an internal-workflow signal, so it is
 * neither reported nor rendered for requesters in the helpdesk interface.
 */

use GlpiPlugin\Glpipresence\GarbageCollector;
use GlpiPlugin\Glpipresence\Renderer;

define('PLUGIN_GLPIPRESENCE_VERSION', '0.1.0');
define('PLUGIN_GLPIPRESENCE_MIN_GLPI', '10.0');

// Settings live under this config context.
define('PLUGIN_GLPIPRESENCE_CONFIG_CONTEXT', 'plugin:glpipresence');

function plugin_init_glpipresence()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpipresence'] = true;

    // Renders the (initially hidden) presence bar at the bottom of the ITIL
    // form; the JS relocates it to the top of #itil-object-container.
    $PLUGIN_HOOKS['post_show_item']['glpipresence'] = 'plugin_glpipresence_post_show_item';

    // Assets are served from public/; the path here is relative to the plugin root.
    $PLUGIN_HOOKS['add_javascript']['glpipresence'] = 'js/presence.js';
    $PLUGIN_HOOKS['add_css']['glpipresence']        = 'css/presence.css';

    $PLUGIN_HOOKS['config_page']['glpipresence'] = 'front/config.php';

    // Offered to glpi-ai's assistant as tools. Registered unconditionally:
    // only glpi-ai reads this hook, so an instance without it pays one array
    // assignment and never loads the class — while guarding on
    // Plugin::isPluginActive('glpiai') would run a database lookup on every
    // request to avoid exactly that.
    $PLUGIN_HOOKS['glpiai_tools']['glpipresence'] = [\GlpiPlugin\Glpipresence\AiTools::class, 'all'];

    // The presence bar, for the technician app. The person on a phone is the
    // one out at a site, least likely to know what the office has already
    // started — which is exactly who collision detection is for.
    $PLUGIN_HOOKS['api_controllers']['glpipresence'] = [\GlpiPlugin\Glpipresence\MobileController::class];

    // Feature discovery for glpi-mobile's /capabilities endpoint, evaluated
    // per session by that plugin.
    $PLUGIN_HOOKS['glpimobile_capabilities']['glpipresence'] = 'plugin_glpipresence_mobile_capabilities';
}

function plugin_version_glpipresence()
{
    return [
        'name'         => 'GLPI Presence',
        'version'      => PLUGIN_GLPIPRESENCE_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-presence',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPIPRESENCE_MIN_GLPI]],
    ];
}

function plugin_glpipresence_check_prerequisites()
{
    return true;
}

function plugin_glpipresence_check_config($verbose = false)
{
    return true;
}

function plugin_glpipresence_post_show_item($params)
{
    $item = $params['item'] ?? null;
    if (!($item instanceof CommonDBTM) || $item->isNewItem()) {
        return;
    }
    Renderer::render($item);
}

/**
 * What of this plugin the mobile app may show the calling user.
 *
 * There is no profile right to consult: presence is readable by anybody who can
 * read the item, and claiming is gated per item at the endpoint. What is
 * decided here is the two things that are true instance-wide — the interface,
 * and whether soft claims are switched on at all.
 *
 * @return array{version:string,features:array<string,bool>}
 */
function plugin_glpipresence_mobile_capabilities(): array
{
    $central = Session::getCurrentInterface() === 'central';

    return [
        'version'  => PLUGIN_GLPIPRESENCE_VERSION,
        'features' => [
            'presence' => $central,
            'claim'    => $central && (bool) \GlpiPlugin\Glpipresence\Settings::get('claim_enabled'),
        ],
    ];
}
