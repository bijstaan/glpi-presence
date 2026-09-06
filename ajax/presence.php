<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Presence heartbeat + claim endpoint.
 *
 * GLPI 11 bootstraps the framework before executing plugin `ajax/` scripts,
 * so there is no includes.php to pull in here.
 *
 * Every action re-authorizes against the addressed item rather than trusting
 * the client's word: the ticket id arrives from the browser, so without a
 * canViewItem() check this endpoint would happily report who is working any
 * ticket in the instance to anyone who can guess an id.
 */

use GlpiPlugin\Glpipresence\Claim;
use GlpiPlugin\Glpipresence\Presence;
use GlpiPlugin\Glpipresence\Settings;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

/** Emit a JSON payload and stop. */
$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

$users_id = (int) Session::getLoginUserID();
if ($users_id <= 0) {
    $respond(['error' => 'unauthenticated'], 401);
}

// Technician-facing only — see Renderer::applies().
if (Session::getCurrentInterface() !== 'central') {
    $respond(['error' => 'forbidden'], 403);
}

// No CSRF check here on purpose. GLPI 11's CheckCsrfListener already ran and
// validated this request before the script was reached, and the two transports
// it accepts behave differently: an XHR's `X-Glpi-Csrf-Token` header is
// *preserved* (which is what makes a 15s heartbeat viable at all against a
// finite token pool), while a plain POST body token is *consumed*. Re-checking
// here would therefore reject every request whose token the listener just ate
// — notably the keepalive/beacon "leave" on page teardown.

$action      = (string) ($_POST['action'] ?? '');
$itemtype    = (string) ($_POST['itemtype'] ?? '');
$items_id    = (int) ($_POST['items_id'] ?? 0);
$session_key = (string) ($_POST['session_key'] ?? '');

if (!Settings::appliesTo($itemtype) || $items_id <= 0) {
    $respond(['error' => 'invalid_item'], 400);
}

// Bound the session key: it is client-generated and lands in a UNIQUE column.
if ($session_key === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $session_key)) {
    $respond(['error' => 'invalid_session_key'], 400);
}

/** @var CommonDBTM $item */
$item = new $itemtype();
if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
    $respond(['error' => 'not_found'], 404);
}

/** Current state of the item, as every action returns it. */
$state = static function () use ($itemtype, $items_id, $users_id): array {
    return [
        'server_time'  => time(),
        'you'          => $users_id,
        'participants' => Presence::participants($itemtype, $items_id),
        'claim'        => Claim::current($itemtype, $items_id),
    ];
};

switch ($action) {
    case 'heartbeat':
        $typing = (string) ($_POST['typing'] ?? '0') === '1';
        $kind   = (string) ($_POST['typing_kind'] ?? '');

        Presence::heartbeat(
            $itemtype,
            $items_id,
            $session_key,
            $users_id,
            $typing,
            $kind !== '' ? $kind : null
        );

        // A claim holder who is still here keeps their claim alive; this is
        // what makes the claim expire on idleness rather than on disconnect.
        $claim = Claim::current($itemtype, $items_id);
        if ($claim !== null && $claim['users_id'] === $users_id) {
            Claim::touch($itemtype, $items_id, $users_id);
        }

        // Opportunistic GC so the table stays correct without a working cron.
        // Sampled rather than run every beat: on a busy instance the sweep
        // would otherwise be the most frequent write in the plugin.
        if (mt_rand(1, 20) === 1) {
            Presence::sweep();
            Claim::sweepIdle();
        }

        $respond($state());
        // no break — respond() exits

    case 'leave':
        Presence::leave($itemtype, $items_id, $session_key);
        $respond(['ok' => true]);

    case 'claim':
    case 'takeover':
        if (!Settings::get('claim_enabled')) {
            $respond(['error' => 'claim_disabled'], 400);
        }
        // Claiming asserts you are doing the work, so it needs write access —
        // a read-only viewer must not be able to park a claim on a ticket.
        if (!$item->canUpdateItem()) {
            $respond(['error' => 'forbidden'], 403);
        }
        if (!Claim::claim($itemtype, $items_id, $users_id, $action === 'takeover')) {
            // Lost a race against another claimer; the caller re-renders from
            // the state we return rather than retrying blindly.
            $respond(['error' => 'already_claimed'] + $state(), 409);
        }
        $respond($state());

    case 'release':
        Claim::release($itemtype, $items_id, $users_id);
        $respond($state());

    default:
        $respond(['error' => 'unknown_action'], 400);
}
