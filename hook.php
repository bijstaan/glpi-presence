<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpipresence\GarbageCollector;

/**
 * Install: two tables and a sweeper cron.
 *
 * Time columns are unix INTs, not TIMESTAMPs — expiry is computed in PHP, and
 * mixing PHP's clock with MySQL's CURRENT_TIMESTAMP makes presence subtly
 * wrong whenever the two disagree on timezone.
 */
function plugin_glpipresence_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    // One row per browser tab, so a technician with the ticket open twice does
    // not have their tabs overwrite each other. Collapsed per user on read.
    if (!$DB->tableExists('glpi_plugin_glpipresence_presence')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpipresence_presence` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `itemtype` VARCHAR(100) NOT NULL,
                `items_id` INT UNSIGNED NOT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `session_key` VARCHAR(64) NOT NULL,
                `typing_until` INT UNSIGNED NOT NULL DEFAULT 0,
                `typing_kind` VARCHAR(32) NULL,
                `last_seen` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `session` (`itemtype`,`items_id`,`session_key`),
                KEY `item_live` (`itemtype`,`items_id`,`last_seen`),
                KEY `sweep` (`last_seen`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // At most one active claim per item; taking over rewrites users_id in place.
    if (!$DB->tableExists('glpi_plugin_glpipresence_claims')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpipresence_claims` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `itemtype` VARCHAR(100) NOT NULL,
                `items_id` INT UNSIGNED NOT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `last_activity` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `item` (`itemtype`,`items_id`),
                KEY `sweep` (`last_activity`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Backstop for the endpoint's opportunistic sweep, which only runs while
    // someone is actually looking at a ticket.
    CronTask::register(
        GarbageCollector::class,
        'Sweep',
        300,
        ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]
    );

    return true;
}

function plugin_glpipresence_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (['presence', 'claims'] as $suffix) {
        $table = "glpi_plugin_glpipresence_$suffix";
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $DB->delete('glpi_crontasks', ['itemtype' => GarbageCollector::class]);

    Config::deleteConfigurationValues(
        PLUGIN_GLPIPRESENCE_CONFIG_CONTEXT,
        array_keys(\GlpiPlugin\Glpipresence\Settings::DEFAULTS)
    );

    return true;
}
