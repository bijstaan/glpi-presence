// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * GLPI Presence — live presence + typing indicator on ITIL forms.
 *
 * Polls a heartbeat endpoint rather than holding a socket: GLPI 11 ships no
 * WebSocket or SSE channel, and a long-lived PHP request per open ticket
 * would tie up an FPM worker each. The poll is visibility-aware, so a tab
 * left open in the background costs one request a minute or so.
 *
 * Typing is reported as a decaying deadline, never as an explicit "stopped"
 * message — a browser that dies mid-sentence must not leave a technician
 * looking like they are still typing.
 */
(function () {
    'use strict';

    // add_javascript loads us from the page head, but the mount point is
    // emitted by post_show_item near the *end* of the form — so at first
    // execution the root reliably does not exist yet. Wait for it rather than
    // bailing, and keep looking briefly afterwards for forms that finish
    // rendering after DOMContentLoaded.
    function whenRootReady(cb) {
        var found = document.querySelector('[data-glpipresence-root]');
        if (found) {
            cb(found);
            return;
        }
        var tries = 0;
        var poll = window.setInterval(function () {
            var el = document.querySelector('[data-glpipresence-root]');
            if (el) {
                window.clearInterval(poll);
                cb(el);
            } else if (++tries > 40) {
                window.clearInterval(poll); // not an ITIL form; nothing to do
            }
        }, 150);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            whenRootReady(init);
        });
    } else {
        whenRootReady(init);
    }

    function init(root) {
    var cfg, labels;
    try {
        cfg = JSON.parse(root.dataset.config);
        labels = JSON.parse(root.dataset.labels);
    } catch (e) {
        return;
    }

    // --- Session identity -------------------------------------------------
    // Per tab, and stable across reloads: sessionStorage is scoped to the tab,
    // so two tabs on the same ticket get distinct keys and stop clobbering
    // each other's heartbeat, while F5 keeps the same identity instead of
    // making the user appear to leave and rejoin.
    var storageKey = 'glpipresence:' + cfg.itemtype + ':' + cfg.items_id;
    var sessionKey = null;
    try {
        sessionKey = window.sessionStorage.getItem(storageKey);
    } catch (e) { /* private mode */ }

    if (!sessionKey) {
        sessionKey = newKey();
        try {
            window.sessionStorage.setItem(storageKey, sessionKey);
        } catch (e) { /* fall through with an in-memory key */ }
    }

    function newKey() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        return Array.prototype.map
            .call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); })
            .join('');
    }

    // --- State ------------------------------------------------------------
    var typingUntil = 0;       // local decay deadline (ms epoch)
    var typingKind = null;     // 'ITILFollowup' | 'TicketTask' | 'ITILSolution'
    var lastSentTyping = false;
    var timer = null;
    var stopped = false;
    var failures = 0;
    var bar = null;
    var me = cfg.users_id;   // resolved identity; refreshed from each response

    var TYPING_LOCAL_MS = 6000; // how long one keystroke implies "still typing"
    var HOT_INTERVAL = 4;       // seconds between polls while the room is busy
    var HOT_WINDOW_MS = 20000;  // how long activity keeps the poll hot
    var hotUntil = 0;

    // --- Transport --------------------------------------------------------

    function payload(action, extra) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('itemtype', cfg.itemtype);
        body.set('items_id', String(cfg.items_id));
        body.set('session_key', sessionKey);
        Object.keys(extra || {}).forEach(function (k) {
            body.set(k, extra[k]);
        });
        return body;
    }

    /**
     * GLPI 11 validates CSRF in the kernel, before the endpoint runs, and it
     * takes the token two different ways: from the X-Glpi-Csrf-Token header on
     * an XHR (preserving it) or from the POST body otherwise (consuming it).
     * Presence must use the header form — a beat every 15s that burned a token
     * each time would empty the session's token pool in minutes and start
     * breaking unrelated forms in the user's other tabs.
     */
    function headers() {
        return {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': cfg.csrf
        };
    }

    function post(action, extra, onDone) {
        if (stopped) {
            return;
        }

        fetch(cfg.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers(),
            body: payload(action, extra).toString()
        })
            .then(function (res) {
                // A rejected token means this page's CSRF token was evicted
                // from GLPI's finite pool. Retrying cannot fix that, so stop
                // rather than hammer the endpoint for the life of the tab.
                if (res.status === 403) {
                    stopped = true;
                    render(null);
                    return null;
                }
                return res.json().catch(function () { return null; });
            })
            .then(function (data) {
                failures = 0;
                if (data && onDone) {
                    onDone(data);
                }
            })
            .catch(function () {
                // Network blip: back off, but never give up entirely — a
                // technician who regains connectivity should reappear.
                failures = Math.min(failures + 1, 5);
            });
    }

    function beat() {
        var typing = Date.now() < typingUntil;
        lastSentTyping = typing;

        post('heartbeat', {
            typing: typing ? '1' : '0',
            typing_kind: typing && typingKind ? typingKind : ''
        }, render);
    }

    function schedule() {
        if (timer) {
            window.clearTimeout(timer);
        }
        if (stopped) {
            return;
        }

        var base;
        if (document.hidden) {
            base = cfg.heartbeat_hidden;
        } else if (Date.now() < hotUntil) {
            // Someone else is mid-sentence. The whole point of this feature is
            // to stop two technicians answering the same ticket at once, and
            // learning about it a poll-interval later is too late to help —
            // so while anything is actually happening, poll fast. This only
            // costs extra requests during the seconds someone is typing.
            base = HOT_INTERVAL;
        } else {
            base = cfg.heartbeat_focused;
        }

        var delay = base * 1000 * (failures ? Math.pow(2, failures) : 1);
        timer = window.setTimeout(function () {
            beat();
            schedule();
        }, delay);
    }

    /** Send a beat right now, e.g. the moment typing starts. */
    function beatNow() {
        beat();
        schedule();
    }

    // --- Typing detection -------------------------------------------------
    //
    // The composer lives in #new-itilobject-form, one collapsible block per
    // answer type. Plain inputs bubble to the document; TinyMCE renders into
    // an iframe whose key events never reach us, so those editors are bound
    // individually as TinyMCE creates them.

    function kindFromNode(node) {
        var block = node && node.closest
            ? node.closest('[id^="new-"][id$="-block"]')
            : null;
        if (!block) {
            return null;
        }
        var m = /^new-(.+)-block$/.exec(block.id);
        return m ? m[1] : null;
    }

    function noteTyping(kind) {
        typingKind = kind;
        var wasTyping = Date.now() < typingUntil;
        typingUntil = Date.now() + TYPING_LOCAL_MS;

        // Transitioning into typing is the one event worth an out-of-band
        // request: waiting up to a full interval to tell the room would let
        // two technicians start writing the same reply.
        if (!wasTyping && !lastSentTyping) {
            beatNow();
        }
    }

    var composer = document.getElementById('new-itilobject-form');
    if (composer) {
        composer.addEventListener('input', function (ev) {
            noteTyping(kindFromNode(ev.target));
        }, true);
    }

    function bindEditor(editor) {
        if (!editor || editor._glpipresenceBound) {
            return;
        }
        editor._glpipresenceBound = true;
        editor.on('input keyup', function () {
            var el = null;
            try {
                el = editor.getElement();
            } catch (e) { /* editor torn down */ }
            noteTyping(kindFromNode(el));
        });
    }

    function bindTinyMce() {
        var tiny = window.tinymce;
        if (!tiny) {
            return false;
        }
        (tiny.editors || []).forEach(bindEditor);
        // The composer creates its editor lazily, when a block is expanded.
        if (tiny.on) {
            tiny.on('AddEditor', function (e) {
                bindEditor(e.editor);
            });
        }
        return true;
    }

    if (!bindTinyMce()) {
        // TinyMCE may load after us; retry briefly rather than miss it.
        var tries = 0;
        var tinyTimer = window.setInterval(function () {
            if (bindTinyMce() || ++tries > 20) {
                window.clearInterval(tinyTimer);
            }
        }, 250);
    }

    // --- Rendering --------------------------------------------------------

    function ensureBar() {
        if (bar) {
            return bar;
        }
        bar = document.createElement('div');
        bar.className = 'glpipresence-bar';
        bar.setAttribute('role', 'status');
        bar.setAttribute('aria-live', 'polite');

        var host = document.getElementById('itil-object-container');
        if (host) {
            host.insertBefore(bar, host.firstChild);
        } else {
            root.parentNode.insertBefore(bar, root);
        }
        return bar;
    }

    function chip(person) {
        var el = document.createElement('span');
        el.className = 'glpipresence-person' + (person.typing ? ' is-typing' : '');

        var avatar = document.createElement('span');
        avatar.className = 'glpipresence-avatar';
        // Stable per-user hue so the same face keeps the same colour.
        avatar.style.backgroundColor = 'hsl(' + ((person.users_id * 137) % 360) + ' 45% 42%)';
        avatar.textContent = person.initials;
        el.appendChild(avatar);

        var text = document.createElement('span');
        text.className = 'glpipresence-label';
        var name = person.users_id === me ? labels.you : person.name;
        if (person.typing) {
            var kindLabel = friendlyKind(person.typing_kind);
            text.textContent = kindLabel
                ? name + ' ' + labels.typing_kind.replace('%s', kindLabel)
                : name + ' ' + labels.typing;
        } else {
            text.textContent = name + ' ' + labels.viewing;
        }
        el.appendChild(text);
        return el;
    }

    function friendlyKind(kind) {
        switch (kind) {
            case 'ITILFollowup': return labels.followup;
            case 'TicketTask':
            case 'ChangeTask':
            case 'ProblemTask': return labels.task;
            case 'ITILSolution': return labels.solution;
            default: return null;
        }
    }

    function button(text, cls, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + cls;
        b.textContent = text;
        b.addEventListener('click', onClick);
        return b;
    }

    function render(data) {
        var el = ensureBar();
        el.textContent = '';

        if (!data) {
            el.className = 'glpipresence-bar is-hidden';
            return;
        }

        // Prefer the identity the server just reported over the one baked into
        // the page: if the session changed under us (re-login in another tab),
        // the rendered config is stale and we would otherwise mistake our own
        // presence for a colleague's.
        me = typeof data.you === 'number' ? data.you : cfg.users_id;

        var others = (data.participants || []).filter(function (p) {
            return p.users_id !== me;
        });
        var claim = data.claim;

        // Anyone else typing means the situation is live — go hot, and
        // re-arm the timer straight away so we do not sit out the remainder
        // of a slow interval that was scheduled before we knew that.
        if (others.some(function (p) { return p.typing; })) {
            var wasHot = Date.now() < hotUntil;
            hotUntil = Date.now() + HOT_WINDOW_MS;
            if (!wasHot) {
                schedule();
            }
        }
        var claimedByOther = claim && claim.users_id !== me;
        var claimedByYou = claim && claim.users_id === me;

        // Nothing to report and nothing to offer: stay out of the way.
        if (!others.length && !claim && !(cfg.claim_enabled && cfg.can_update)) {
            el.className = 'glpipresence-bar is-hidden';
            return;
        }

        el.className = 'glpipresence-bar'
            + (others.length || claim ? ' is-active' : ' is-quiet')
            + (claimedByOther ? ' is-claimed' : '');

        var people = document.createElement('div');
        people.className = 'glpipresence-people';

        if (claim) {
            var holder = document.createElement('span');
            holder.className = 'glpipresence-person is-claim';
            var icon = document.createElement('span');
            icon.className = 'glpipresence-claimicon';
            icon.textContent = '🔧';
            holder.appendChild(icon);
            var who = document.createElement('span');
            who.className = 'glpipresence-label';
            who.textContent = (claimedByYou ? labels.you : claim.name)
                + ' ' + labels.working + ' (' + since(claim.since, data.server_time) + ')';
            holder.appendChild(who);
            people.appendChild(holder);
        }

        others.forEach(function (p) {
            // Already shown as the claim holder; don't say their name twice
            // unless they are actively typing, which is new information.
            if (claim && p.users_id === claim.users_id && !p.typing) {
                return;
            }
            people.appendChild(chip(p));
        });

        if (!people.childNodes.length) {
            var alone = document.createElement('span');
            alone.className = 'glpipresence-label glpipresence-alone';
            alone.textContent = labels.alone;
            people.appendChild(alone);
        }

        el.appendChild(people);

        if (cfg.claim_enabled && cfg.can_update) {
            var actions = document.createElement('div');
            actions.className = 'glpipresence-actions';

            if (claimedByOther) {
                actions.appendChild(button(labels.take_over, 'btn-outline-warning', function () {
                    if (window.confirm(labels.confirm_take.replace('%s', claim.name))) {
                        post('takeover', {}, render);
                    }
                }));
                actions.appendChild(button(labels.work_along, 'btn-ghost-secondary', function () {
                    el.classList.add('is-dismissed');
                }));
            } else if (claimedByYou) {
                actions.appendChild(button(labels.release, 'btn-ghost-secondary', function () {
                    post('release', {}, render);
                }));
            } else {
                actions.appendChild(button(labels.claim, 'btn-outline-primary', function () {
                    post('claim', {}, render);
                }));
            }

            el.appendChild(actions);
        }
    }

    function since(startSeconds, nowSeconds) {
        var mins = Math.max(0, Math.round((nowSeconds - startSeconds) / 60));
        if (mins < 1) {
            return '<1m';
        }
        if (mins < 60) {
            return mins + 'm';
        }
        return Math.floor(mins / 60) + 'h' + (mins % 60 ? (mins % 60) + 'm' : '');
    }

    // --- Lifecycle --------------------------------------------------------

    document.addEventListener('visibilitychange', function () {
        // Re-scheduling on visibility change swaps the interval immediately;
        // coming back to the tab also gets a fresh read right away.
        if (!document.hidden) {
            beatNow();
        } else {
            schedule();
        }
    });

    window.addEventListener('pagehide', function () {
        // Leaving must outlive the page, so the request cannot be an ordinary
        // fetch (it would be aborted on teardown).
        //
        // keepalive is preferred over sendBeacon because it still carries our
        // headers, which keeps the CSRF token in the *preserved* header path.
        // sendBeacon cannot set headers, so its token goes in the body and the
        // kernel consumes it — harmless when the page is really going away,
        // but it leaves a bfcache-restored page holding a dead token. Hence
        // keepalive first, beacon only as a fallback.
        var body = payload('leave', {}).toString();

        if (supportsKeepalive()) {
            fetch(cfg.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers(),
                body: body,
                keepalive: true
            }).catch(function () { /* page is going away */ });
            return;
        }

        if (navigator.sendBeacon) {
            var beaconBody = body + '&_glpi_csrf_token=' + encodeURIComponent(cfg.csrf);
            navigator.sendBeacon(
                cfg.endpoint,
                new Blob([beaconBody], {
                    type: 'application/x-www-form-urlencoded; charset=UTF-8'
                })
            );
        }
    });

    function supportsKeepalive() {
        try {
            return 'keepalive' in new Request('', { method: 'POST' });
        } catch (e) {
            return false;
        }
    }

    // A page restored from the bfcache resumes with its timers stopped; beat
    // immediately so the technician does not sit there looking absent.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted && !stopped) {
            beatNow();
        }
    });

    beatNow();
    } // init
})();
