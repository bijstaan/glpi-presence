<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpipresence\Settings;

Session::checkRight('config', READ);

if (!empty($_POST['update'])) {
    // No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated
    // and consumed the token before this page ran, so a second check always
    // fails. The hidden field in the form is what matters.
    Session::checkRight('config', UPDATE);

    Settings::save([
        'itemtypes'         => implode(',', (array) ($_POST['itemtypes'] ?? [])),
        'heartbeat_focused' => (int) ($_POST['heartbeat_focused'] ?? 0),
        'heartbeat_hidden'  => (int) ($_POST['heartbeat_hidden'] ?? 0),
        'presence_ttl'      => (int) ($_POST['presence_ttl'] ?? 0),
        'typing_ttl'        => (int) ($_POST['typing_ttl'] ?? 0),
        'claim_enabled'     => !empty($_POST['claim_enabled']) ? '1' : '0',
        'claim_idle_ttl'    => (int) ($_POST['claim_idle_ttl'] ?? 0),
    ]);

    Session::addMessageAfterRedirect(__s('Settings saved.', 'glpipresence'));
    Html::back();
}

Html::header(
    __('GLPI Presence', 'glpipresence'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$cfg  = Settings::all();
$e    = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = Session::getNewCSRFToken();

$types    = Settings::itemtypes();
$eligible = ['Ticket', 'Change', 'Problem'];

// Read-only visitors keep the page but lose the button.
//
// READ opens this page and UPDATE saves it, and the two are separately
// grantable — so a profile can legitimately arrive here unable to change
// anything. Rendering the form as though they could, and answering Save with an
// access-denied page, wastes the work they just did explaining nothing.
$can_edit = Session::haveRight('config', UPDATE);

// Dark-palette helper-text rules used to be an inline <style> here, as a
// property override on `.text-muted` — which core's own `!important`
// declaration silently beats, so it never worked. The working fix (redefine
// the --tblr-muted / --tblr-secondary-color VARIABLES inside the
// `.glpipresence-config` scope) ships in this plugin's already-global
// public/css/presence.css.
echo "<div class='container-fluid glpipresence-config' style='max-width:960px'>";

if (!$can_edit) {
    echo "<div class='alert alert-info py-2'>"
       . __s('Read only: you can see these settings but not change them.', 'glpipresence')
       . '</div>';
}
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);

// --- Scope ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . __s('Where presence is shown', 'glpipresence') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
    . __s(
        'Presence is only ever shown in the technician (central) interface — requesters never see who is working their ticket.',
        'glpipresence'
    )
    . '</p>';
foreach ($eligible as $type) {
    if (!class_exists($type)) {
        continue;
    }
    $checked = in_array($type, $types, true) ? "checked='checked'" : '';
    echo "<label class='form-check'>";
    echo "<input type='checkbox' class='form-check-input' name='itemtypes[]' "
        . "value='" . $e($type) . "' $checked>";
    echo "<span class='form-check-label'>" . $e($type::getTypeName(2)) . '</span>';
    echo '</label>';
}
echo '</div></div>';

// --- Timing ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . __s('Timing', 'glpipresence') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
    . __s(
        'Values that contradict each other are corrected automatically — the visibility window can never be shorter than the background heartbeat, so a backgrounded tab cannot flicker in and out of the list.',
        'glpipresence'
    )
    . '</p>';
echo "<div class='row'>";

$numbers = [
    'heartbeat_focused' => __s('Heartbeat while the tab is focused (seconds)', 'glpipresence'),
    'heartbeat_hidden'  => __s('Heartbeat while the tab is in the background (seconds)', 'glpipresence'),
    'presence_ttl'      => __s('Treat a technician as gone after (seconds)', 'glpipresence'),
    'typing_ttl'        => __s('“is typing” fades after (seconds)', 'glpipresence'),
];
foreach ($numbers as $key => $label) {
    echo "<div class='col-md-6 mb-3'>";
    echo "<label class='form-label'>$label</label>";
    echo "<input type='number' min='1' class='form-control' name='" . $e($key) . "' "
        . "value='" . $e($cfg[$key]) . "'>";
    echo '</div>';
}
echo '</div></div></div>';

// --- Claim ---
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . __s('Soft claim', 'glpipresence') . '</h3></div>';
echo "<div class='card-body'>";
echo '<p class="text-muted">'
    . __s(
        'The claim is advisory: it tells the room who picked the work up, and never blocks anyone from editing. It is released automatically once its holder goes quiet.',
        'glpipresence'
    )
    . '</p>';
$claimChecked = ((int) $cfg['claim_enabled']) === 1 ? "checked='checked'" : '';
echo "<label class='form-check mb-3'>";
echo "<input type='checkbox' class='form-check-input' name='claim_enabled' value='1' $claimChecked>";
echo "<span class='form-check-label'>"
    . __s('Let technicians claim a ticket', 'glpipresence') . '</span>';
echo '</label>';
echo "<div class='mb-2' style='max-width:340px'>";
echo "<label class='form-label'>"
    . __s('Release a claim after this much inactivity (seconds)', 'glpipresence') . '</label>';
echo "<input type='number' min='60' class='form-control' name='claim_idle_ttl' "
    . "value='" . $e($cfg['claim_idle_ttl']) . "'>";
echo '</div>';
echo '</div></div>';

echo "<div class='text-end mb-4'>";
if ($can_edit) {
    echo "<button type='submit' name='update' value='1' class='btn btn-primary'>"
        . __s('Save', 'glpipresence') . '</button>';
}
echo '</div>';

echo '</form></div>';

Html::footer();
