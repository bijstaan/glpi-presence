<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipresence;

use CommonDBTM;
use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Session;

/**
 * Who else is on this ticket, from the app.
 *
 * The question a technician has the moment they open a ticket does not change
 * because they opened it on a phone: is somebody already on this, and has
 * anyone picked the work up? It arguably matters *more* there — the person on
 * the phone is the one out at a site, least likely to know what the office has
 * already started, and most likely to duplicate it.
 *
 * The same three actions the web bar has — beat, claim, release — and the same
 * authority for each: seeing presence takes read access to the item, claiming
 * takes write access, because a claim asserts that you are doing the work.
 *
 * **The heartbeat cadence is the caller's problem, and should be slower here.**
 * The browser beats every eight seconds because a tab is either in front of
 * somebody or it is not. A phone screen is off most of the time, radio wake-ups
 * cost battery, and a technician who is *looking* at the ticket is looking at
 * it for a minute, not an afternoon. The server does not care: presence expires
 * on its own TTL, so a client that beats slowly simply appears and disappears
 * more coarsely, which is the right trade on a battery.
 */
#[Route(path: '/GlpiPresence', tags: ['GlpiPresence'])]
final class MobileController extends AbstractController
{
    protected static function getRawKnownSchemas(): array
    {
        return [];
    }

    /** Optional-parameter read: core's getParameter() warns on absent keys. */
    private static function param(Request $request, string $name, mixed $default = null): mixed
    {
        return $request->hasParameter($name) ? $request->getParameter($name) : $default;
    }

    /**
     * Who is here, and who holds the claim.
     *
     * A read, so it does not announce the caller. Opening the screen and
     * appearing in everybody else's participant list are two different
     * decisions, and an app that wants both sends a heartbeat.
     */
    #[Route(
        path: '/item/{itemtype}/{items_id}',
        methods: ['GET'],
        requirements: ['itemtype' => '\w+', 'items_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function state(Request $request): Response
    {
        $item = self::viewableItem($request);
        if ($item instanceof Response) {
            return $item;
        }

        return new JSONResponse(self::snapshot($item), 200);
    }

    /**
     * "I am here." Body `{session_key, typing, typing_kind}`.
     *
     * The session key is client-generated and lands in a UNIQUE column, so it
     * is bounded here exactly as the web endpoint bounds it. One key per
     * installation of the app per item is the intent — reusing one across two
     * items is harmless, reusing one across two devices makes them a single
     * participant.
     */
    #[Route(
        path: '/item/{itemtype}/{items_id}/heartbeat',
        methods: ['POST'],
        requirements: ['itemtype' => '\w+', 'items_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function heartbeat(Request $request): Response
    {
        $item = self::viewableItem($request);
        if ($item instanceof Response) {
            return $item;
        }

        $key = self::sessionKey($request);
        if ($key === null) {
            return new JSONResponse(['error' => 'invalid_session_key'], 400);
        }

        $users_id = (int) Session::getLoginUserID();
        $kind     = (string) self::param($request, 'typing_kind', '');

        Presence::heartbeat(
            $item::getType(),
            (int) $item->getID(),
            $key,
            $users_id,
            self::truthy(self::param($request, 'typing')),
            $kind !== '' ? $kind : null
        );

        // A claim holder who is still here keeps their claim alive; this is what
        // makes a claim expire on idleness rather than on disconnect.
        $claim = Claim::current($item::getType(), (int) $item->getID());
        if ($claim !== null && $claim['users_id'] === $users_id) {
            Claim::touch($item::getType(), (int) $item->getID(), $users_id);
        }

        // Opportunistic GC, sampled — on a busy instance an every-beat sweep
        // would be the most frequent write in the plugin.
        if (mt_rand(1, 20) === 1) {
            Presence::sweep();
            Claim::sweepIdle();
        }

        return new JSONResponse(self::snapshot($item), 200);
    }

    /**
     * Stop being here. Body `{session_key}`.
     *
     * Worth calling when the screen closes, but never worth waiting for: the
     * TTL removes a participant who simply vanishes, which is what happens
     * whenever a phone loses signal.
     */
    #[Route(
        path: '/item/{itemtype}/{items_id}/leave',
        methods: ['POST'],
        requirements: ['itemtype' => '\w+', 'items_id' => '\d+']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function leave(Request $request): Response
    {
        $item = self::viewableItem($request);
        if ($item instanceof Response) {
            return $item;
        }

        $key = self::sessionKey($request);
        if ($key === null) {
            return new JSONResponse(['error' => 'invalid_session_key'], 400);
        }

        Presence::leave($item::getType(), (int) $item->getID(), $key);

        return new JSONResponse(['ok' => true], 200);
    }

    /**
     * Claim the work, take it over, or hand it back.
     *
     * `claim` against an item somebody else holds fails with 409 rather than
     * stealing it — taking over has to be a thing the technician chose, not
     * something a retry can do by accident. The state comes back either way,
     * so a client that lost the race re-renders from the truth instead of
     * guessing.
     */
    #[Route(
        path: '/item/{itemtype}/{items_id}/{action}',
        methods: ['POST'],
        requirements: ['itemtype' => '\w+', 'items_id' => '\d+', 'action' => 'claim|takeover|release']
    )]
    #[RouteVersion(introduced: '2.0')]
    public function claim(Request $request): Response
    {
        $item = self::viewableItem($request);
        if ($item instanceof Response) {
            return $item;
        }

        if (!Settings::get('claim_enabled')) {
            return new JSONResponse(['error' => 'claim_disabled'], 400);
        }

        // Claiming asserts you are doing the work, so it takes write access — a
        // read-only viewer must not be able to park a claim on a ticket.
        if (!$item->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $action   = (string) $request->getAttribute('action');
        $users_id = (int) Session::getLoginUserID();

        if ($action === 'release') {
            Claim::release($item::getType(), (int) $item->getID(), $users_id);

            return new JSONResponse(self::snapshot($item), 200);
        }

        if (!Claim::claim($item::getType(), (int) $item->getID(), $users_id, $action === 'takeover')) {
            return new JSONResponse(['error' => 'already_claimed'] + self::snapshot($item), 409);
        }

        return new JSONResponse(self::snapshot($item), 200);
    }

    // -------------------------------------------------------------- helpers

    /**
     * The item this route addresses, or the response saying why not.
     *
     * The id arrives from a client, so without the read check this endpoint
     * would report who is working any ticket in the instance to anybody who
     * can guess an id.
     */
    private static function viewableItem(Request $request): CommonDBTM|Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        // Technician-facing only — see Renderer::applies().
        if (Session::getCurrentInterface() !== 'central') {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $itemtype = (string) $request->getAttribute('itemtype');
        $items_id = (int) $request->getAttribute('items_id');

        if (!Settings::appliesTo($itemtype) || !class_exists($itemtype)) {
            return new JSONResponse(['error' => 'invalid_item'], 400);
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if ($items_id <= 0 || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        return $item;
    }

    /** The client's key, bounded, or null when it is not usable. */
    private static function sessionKey(Request $request): ?string
    {
        $key = (string) self::param($request, 'session_key', '');

        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) === 1 ? $key : null;
    }

    /** JSON booleans and form-style strings mean the same thing here. */
    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * The item's presence state, as every route returns it.
     *
     * `server_time` is not decoration: "claimed 40 minutes ago" is computed
     * from timestamps this server produced, and a phone whose clock is off by
     * an hour would otherwise render a claim as stale or as impossible.
     *
     * @return array<string,mixed>
     */
    private static function snapshot(CommonDBTM $item): array
    {
        $itemtype = $item::getType();
        $items_id = (int) $item->getID();

        return [
            'server_time'  => time(),
            'you'          => (int) Session::getLoginUserID(),
            'can_claim'    => (bool) Settings::get('claim_enabled') && $item->canUpdateItem(),
            'presence_ttl' => (int) Settings::get('presence_ttl'),
            'participants' => Presence::participants($itemtype, $items_id),
            'claim'        => Claim::current($itemtype, $items_id),
        ];
    }
}
