<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipresence;

use CommonITILObject;
use GlpiPlugin\Glpiai\Tool;
use Ticket;

/**
 * Who else is on this, offered to glpi-ai's assistant as a tool.
 *
 * The collision this plugin exists to prevent has an AI-shaped version of it:
 * a technician asks the assistant to write a note, run a diagnosis or take the
 * next step on a ticket somebody else picked up four minutes ago, and nothing
 * in the conversation knows. The panel on the page says so; the model cannot
 * see the panel.
 *
 * So this is the one thing a model should check before *acting* on a ticket
 * rather than merely reading one, and the description says so in those words.
 *
 * Read only, and pointedly so. Claiming an item is a person saying "I have
 * this", and a model claiming on somebody's behalf would put a name against
 * work they have not agreed to do — which is the exact failure this plugin was
 * built to make visible.
 */
final class AiTools
{
    /** @return Tool[] */
    public static function all(): array
    {
        return [self::whoIsOnIt()];
    }

    private static function whoIsOnIt(): Tool
    {
        return new Tool(
            name: 'who_is_on_it',
            description: 'Who else is looking at this ticket, change or problem right now, who is '
                . 'typing on it, and who has claimed the work. Check this before writing '
                . 'anything to a ticket or telling a technician to take the next step on one — '
                . 'two people working the same ticket is how a customer gets two different '
                . 'answers, and the person already on it may be mid-reply.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'itemtype' => [
                        'type'        => 'string',
                        'description' => 'Ticket, Change or Problem. Defaults to Ticket.',
                    ],
                    'items_id' => [
                        'type'        => 'integer',
                        'description' => 'Its id. Omit to use the item the conversation is about.',
                    ],
                ],
            ],
            handler: [self::class, 'runWhoIsOnIt'],
            // No right of its own: presence is visible to anyone who can see
            // the item, which is what the check below asks. There is no
            // profile right that means "may see who else is here" to gate on.
            right: null,
            source: 'glpipresence'
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runWhoIsOnIt(array $arguments = [], mixed $context = null): array
    {
        $itemtype = trim((string) ($arguments['itemtype'] ?? ''));
        $items_id = (int) ($arguments['items_id'] ?? 0);

        if ($items_id <= 0 && $context instanceof \GlpiPlugin\Glpiai\ToolContext
            && (int) $context->items_id > 0) {
            $itemtype = $itemtype !== '' ? $itemtype : (string) $context->itemtype;
            $items_id = (int) $context->items_id;
        }

        $itemtype = $itemtype !== '' ? $itemtype : Ticket::class;

        if (!is_a($itemtype, CommonITILObject::class, true) || !Settings::appliesTo($itemtype)) {
            return ['error' => 'Presence is only tracked on tickets, changes and problems.'];
        }

        if ($items_id <= 0) {
            return ['error' => 'Name the id of the ticket, change or problem.'];
        }

        /** @var CommonITILObject $item */
        $item = new $itemtype();
        if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
            return ['error' => sprintf('There is no %s %d that you can see.', $itemtype, $items_id)];
        }

        $now     = time();
        $viewers = [];

        foreach (Presence::participants($itemtype, $items_id) as $person) {
            // The signed-in user is dropped. "You are looking at this ticket"
            // is not news, and leaving it in is how a model concludes that two
            // people are on something when one of them is the person asking.
            if ((int) $person['users_id'] === (int) \Session::getLoginUserID()) {
                continue;
            }

            $viewers[] = array_filter([
                'name'        => (string) $person['name'],
                'typing'      => (bool) $person['typing'],
                'typing_kind' => $person['typing_kind'] ?? null,
                'here_for'    => self::since((int) $person['since'], $now),
            ], static fn($v): bool => $v !== null && $v !== false);
        }

        $claim = Claim::current($itemtype, $items_id);

        return array_filter([
            'item'    => ['itemtype' => $itemtype, 'id' => $items_id],
            'viewers' => $viewers,
            'claimed_by' => $claim === null ? null : array_filter([
                'name'  => (string) $claim['name'],
                'since' => self::since((int) $claim['since'], $now),
                'idle'  => $claim['idle'] > 60
                    ? sprintf('%d minutes', (int) round(((int) $claim['idle']) / 60))
                    : null,
                'is_you' => (int) $claim['users_id'] === (int) \Session::getLoginUserID(),
            ], static fn($v): bool => $v !== null),
            'note'    => self::advice($viewers, $claim),
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * What the model should do about it, in one line.
     *
     * Said explicitly because "somebody else is typing" is a fact a model will
     * happily report and then ignore. The advice is what turns it into
     * behaviour.
     *
     * @param array<int,array<string,mixed>> $viewers
     * @param array<string,mixed>|null       $claim
     */
    private static function advice(array $viewers, ?array $claim): string
    {
        $typing = array_filter($viewers, static fn(array $v): bool => !empty($v['typing']));

        if ($typing !== []) {
            return 'Somebody else is typing on this right now. Say so before writing anything to '
                 . 'the ticket, and offer to wait.';
        }

        if ($claim !== null && (int) $claim['users_id'] !== (int) \Session::getLoginUserID()) {
            return sprintf(
                '%s has claimed this ticket. Anything written on it should say who it came from, '
                . 'and taking the work over is their call rather than yours to assume.',
                (string) $claim['name']
            );
        }

        if ($viewers !== []) {
            return 'Somebody else has this open but is not typing. Worth mentioning before making '
                 . 'changes.';
        }

        return 'Nobody else is on it.';
    }

    /** A duration a person would say out loud. */
    private static function since(int $timestamp, int $now): string
    {
        $minutes = max(0, (int) round(($now - $timestamp) / 60));

        if ($minutes < 1) {
            return 'just now';
        }

        if ($minutes < 60) {
            return sprintf('%d minutes', $minutes);
        }

        return sprintf('%d hours', (int) round($minutes / 60));
    }
}
