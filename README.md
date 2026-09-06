# GLPI Presence

Collision detection and live presence for technicians, on GLPI ITIL objects.

Answers the question a technician has the moment they open a ticket: **is
someone else already on this, are they writing right now, and has anyone
picked the work up?**

![claimed](docs/screenshots/presence-claimed.png)

## Why not GLPI's own `ObjectLock`?

GLPI core already ships item locking, and it solves a different problem.

| | `ObjectLock` (core) | This plugin |
|---|---|---|
| Model | Pessimistic, exclusive | Advisory, informational |
| Holders | Exactly one (`UNIQUE (itemtype, items_id)`) | Any number of participants |
| Effect on others | Forced into a read-only profile | None — never blocks |
| Granularity | Locked / not locked | Viewing vs. typing vs. claimed |
| Recovery | Cron sweep measured in hours | Seconds (TTL), plus explicit leave |
| Default | Off, opt-in per itemtype | On for Ticket/Change/Problem |

The two can coexist. If you want genuine enforcement, turn on core locking;
if you want technicians to *see each other* and stop duplicating work without
being locked out, use this.

## What it does

- **Presence** — who else has this ticket open, one entry per person
  (a technician with two tabs open shows once).
- **Typing** — who is composing a reply, task, or solution right now, with a
  pulsing indicator. Decays on its own, so a crashed browser never leaves a
  ghost "still typing".
- **Soft claim** — a voluntary "I'm working this". Others see the holder and
  are offered **Take over** or **Work alongside**. Nobody is ever blocked.
  Claims expire on *inactivity*, not on disconnect, so stepping away for two
  minutes doesn't drop your claim.

Technician (central) interface only. Requesters never see presence, and their
own presence is never reported.

## The assistant can ask too

Where [glpi-ai](../glpi-ai) is installed, this plugin registers one read-only
tool with it: **`who_is_on_it`** — who else has this ticket open, who is typing
on it, and who has claimed the work.

The collision this plugin exists to prevent has an AI-shaped version: a
technician asks the assistant to write a note or take the next step on a ticket
somebody picked up four minutes ago, and nothing in the conversation knows. The
panel says so; the model cannot see the panel.

So the tool's description tells the model to check it *before acting on* a
ticket rather than merely to report it, and the answer carries the advice with
it — "somebody else is typing on this right now, say so before writing anything
and offer to wait". The signed-in user is left out of the viewer list, because
"you are looking at this ticket" is not news and a model handed it concludes
two people are on the ticket.

Claiming is deliberately not offered. A claim is a person saying "I have this",
and a model claiming on somebody's behalf would put their name against work
they have not agreed to do — which is the exact failure this plugin was built
to make visible.

## Install

```bash
# from the GLPI root
git clone https://github.com/bijstaan/glpi-presence.git plugins/glpipresence
php bin/console plugin:install -u glpi glpipresence
php bin/console plugin:activate glpipresence
```

Settings live at **Setup → Plugins → GLPI Presence**.

| Setting | Default | Notes |
|---|---|---|
| Itemtypes | Ticket, Change, Problem | Where the bar appears |
| Heartbeat (focused) | 8s | Must stay under the typing TTL — see below |
| Heartbeat (background) | 45s | Backgrounded tabs cost far less |
| Presence TTL | 90s | When a silent technician is treated as gone |
| Typing TTL | 12s | How fast "is typing" fades |
| Claim enabled | yes | Turn off for presence-only |
| Claim idle TTL | 30m | Inactivity before a claim is released |

Contradictory values are reconciled rather than rejected — a bad number
degrades to the nearest sane one instead of taking presence down.

### One timing rule worth knowing

The focused heartbeat must be **shorter** than the typing TTL. Presence is a
sampling system: if you poll every 15s but "typing" decays after 12s, a
colleague can type an entire sentence between two polls and you will never
once observe it. `Settings::reconcile()` enforces this by raising the typing
TTL if you set a slow heartbeat, but the better fix is a fast heartbeat.

Polls also go **hot** (4s) for 20s whenever someone else is typing, so an
active exchange stays live without paying for that cadence all day.

## How it works

There is no WebSocket or SSE channel in GLPI 11, and holding a long-lived PHP
request per open ticket would pin an FPM worker each — so this polls.

```
browser (public/js/presence.js)
   │  POST ajax/presence.php   {heartbeat|leave|claim|takeover|release}
   ▼
Presence  ── glpi_plugin_glpipresence_presence   (one row per browser tab)
Claim     ── glpi_plugin_glpipresence_claims     (one row per item)
   ▲
GarbageCollector (cron, 5m)  +  sampled sweep on ~1 in 20 heartbeats
```

Times are stored as unix integers, not SQL `TIMESTAMP`s: expiry is computed in
PHP, and mixing PHP's clock with MySQL's `CURRENT_TIMESTAMP` makes presence
intermittently wrong the moment the two disagree on timezone.

Departure is reported with a `keepalive` fetch on `pagehide`, so closing a tab
removes you immediately instead of waiting out the TTL. `sendBeacon` is only a
fallback — it cannot set headers, and headers are what keep the CSRF token in
GLPI's *preserving* validation path (see below).

### CSRF

GLPI 11 validates CSRF in the kernel, before the endpoint runs, and treats the
two transports differently:

- **XHR** — token from the `X-Glpi-Csrf-Token` header, **preserved**.
- **Plain POST** — token from the body, **consumed**.

A heartbeat every 8s that consumed a token would empty the session's finite
token pool within minutes and start breaking unrelated forms in the user's
other tabs. So the client always sends the header form, and the endpoint does
**not** re-check CSRF itself — a second check would reject every request whose
token the kernel just consumed.

## The technician app

The same three actions the web bar has, over the high-level API, for
glpi-mobile. Every route re-authorises against the addressed item — reading
presence takes read access, claiming takes write access, because a claim
asserts that you are doing the work — and all of them are central-interface
only.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/GlpiPresence/item/{itemtype}/{items_id}` | who is here, and who holds the claim |
| `POST` | `/GlpiPresence/item/{itemtype}/{items_id}/heartbeat` | "I am here", optionally "I am typing" |
| `POST` | `/GlpiPresence/item/{itemtype}/{items_id}/leave` | stop being here |
| `POST` | `/GlpiPresence/item/{itemtype}/{items_id}/claim` | claim the work (409 when somebody holds it) |
| `POST` | `/GlpiPresence/item/{itemtype}/{items_id}/takeover` | take an existing claim, deliberately |
| `POST` | `/GlpiPresence/item/{itemtype}/{items_id}/release` | hand it back |

Every route answers with the same state object — `server_time`, `you`,
`can_claim`, `presence_ttl`, `participants`, `claim` — so a client that lost a
race re-renders from the truth instead of guessing. `server_time` is there
because "claimed 40 minutes ago" is computed from timestamps this server
produced, and a phone's clock may be an hour out.

**The app beats far more slowly than the browser does, and that is correct.**
The web bar beats every eight seconds because a tab is either in front of
somebody or it is not; a phone screen is off most of the time and radio
wake-ups cost battery. Presence expires on `presence_ttl` regardless, so a slow
client simply appears and disappears more coarsely.

Feature discovery goes through glpi-mobile's `glpimobile_capabilities` hook:
`presence` (a central-interface session) and `claim` (soft claims switched on).

## Testing

`glpi-presence/tests/browser/presence-check.js` drives two real technicians through the
whole lifecycle — both arriving, typing, claiming, taking over, and leaving:

```bash
cd glpi-presence/tests/browser && node presence-check.js
```

## Not in this version

- **Take-over notification.** Taking a claim is silent; the previous holder
  finds out by looking. Wiring it to GLPI notifications is the obvious follow-up.
- **Claim history.** Claims are not written to the ticket's Historical tab.

## Licence

GNU General Public License, version 3 or later — the same licence as GLPI.
This plugin is loaded into GLPI's process and extends its classes, so it is a
derivative work of GLPI and carries GLPI's licence. See [LICENSE](LICENSE).
