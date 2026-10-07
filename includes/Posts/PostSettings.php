<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Posts;

use ChurchToolsPlugin\Db\Installer;

/**
 * Die Einstellungen des Bereichs „Beiträge": ob Beitraege uebernommen werden
 * und wie oft.
 *
 * Eine eigene Option wie bei den Gruppen (siehe GroupSettings fuer die
 * Gruende). Der Schalter steht bewusst vor allem anderen: Ohne ihn fragt das
 * Plugin ChurchTools nicht nach Beitraegen, auch wenn die Verbindung steht
 * (Nutzerentscheidung 2026-10-07: „nur, wenn im WP-Backend der Sync von
 * Beitraegen aktiviert ist"). Eine Auswahl wie die Kalender oder Homepages
 * gibt es nicht - ChurchTools entscheidet ueber die Sichtbarkeit von Gruppe
 * und Beitrag, was oeffentlich ist; welche Gruppen eine Seite zeigt, waehlt
 * die Einbindung.
 */
final class PostSettings
{
    public const OPTION_KEY = 'ctp_post_settings';

    /** Die Optionsgruppe fuer settings_fields() im Formular des Reiters. */
    public const OPTION_GROUP = 'churchtools-plugin-posts';

    public const INTERVALS = Installer::SYNC_INTERVALS;

    /**
     * Stuendlich wie bei den Terminen, nicht taeglich wie bei den Gruppen:
     * Ein Beitrag ist eine Neuigkeit, und ein Lauf ist ein einziger Abruf.
     */
    public const DEFAULT_INTERVAL = 'hourly';

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'sync_interval' => self::DEFAULT_INTERVAL,
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args(is_array($stored) ? $stored : [], self::defaults());

        $settings['enabled'] = !empty($settings['enabled']);

        if (!in_array($settings['sync_interval'], self::INTERVALS, true)) {
            $settings['sync_interval'] = self::DEFAULT_INTERVAL;
        }

        return [
            'enabled' => $settings['enabled'],
            'sync_interval' => $settings['sync_interval'],
        ];
    }

    public static function isEnabled(?array $settings = null): bool
    {
        $settings ??= self::get();

        return !empty($settings['enabled']);
    }

    /**
     * Ein fehlender Schluessel heisst „nicht abgeschickt", nicht „leeren" -
     * dieselbe Regel wie in GroupSettings::sanitize(). Der Schalter kommt mit
     * einem versteckten `0`-Zwilling, damit „abgehakt" von „nicht auf dieser
     * Seite" unterscheidbar bleibt.
     */
    public static function sanitize(?array $input): array
    {
        $input ??= [];
        $existing = self::get();

        $interval = $existing['sync_interval'];
        if (array_key_exists('sync_interval', $input) && in_array($input['sync_interval'], self::INTERVALS, true)) {
            $interval = $input['sync_interval'];
        }

        return [
            'enabled' => array_key_exists('enabled', $input) ? !empty($input['enabled']) : $existing['enabled'],
            'sync_interval' => $interval,
        ];
    }
}
