<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Sync\SyncEngine;

/**
 * Die Seite „Übersicht": Terminpanel, die Panels von Gruppen und Beiträgen,
 * Neuigkeiten aus dem Changelog und die Vorbereitung auf 2.0.0.
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan). Die Bausteine, die alle Bereiche teilen
 * (Statuszeile, Zeilen, Schnelllinks, Aktionsleiste), bleiben in
 * SettingsPage.
 */
final class OverviewTab
{
    /**
     * Landing tab (the Übersicht area): bundles what was previously scattered
     * across the Verbindung/Sync/Updates tabs into a single at-a-glance
     * overview, per the "Welcome/Status-Seite"-idea in plan.md.
     */
    public static function render(): void
    {
        $facts = SettingsPage::statusFacts();
        $settings = $facts['settings'];
        $lastError = SyncEngine::getLastError();

        // Derselbe Befund, den SyncHealthNotice auf jeder anderen Admin-Seite
        // meldet - hier, weil dieser Tab das Ziel seines Links ist und der
        // Hinweis sich dort ausblendet. Ohne das waere die Uebersicht die eine
        // Seite, auf der ein stehengebliebener Sync unerwaehnt bleibt.
        $health = SyncHealthNotice::problem($settings);
        $update = UpdatesTab::updateStatus();
        ?>
        <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
            <?php // Gilt fuer Termine und Gruppen, steht deshalb vor beiden Panels statt in einem davon. ?>
            <div class="notice notice-info inline">
                <p>
                    <?php esc_html_e('WP-Cron ist per DISABLE_WP_CRON deaktiviert. Der geplante Sync läuft dann nur, wenn ein System-Cronjob wp-cron.php regelmäßig aufruft (siehe readme.txt).', 'churchtools-plugin'); ?>
                </p>
            </div>
        <?php endif; ?>
        <div class="ctp-panel">
            <?php // Seit der Teilung in Bereiche (2026-09-14) neben dem Panel „Gruppen" darunter - vorher „Verbindung & Betrieb", obwohl alles darin die Termine betrifft. ?>
            <h2><?php esc_html_e('Events', 'churchtools-plugin'); ?></h2>
            <?php
            /*
             * Aktionsleiste direkt unter der Ueberschrift, nicht mehr am Fuss
             * des Panels: dieselbe Position, an der auch die Tabs „Kalender“
             * und „Synchronisation“ ihre Aktion anbieten (siehe
             * renderActionBar()).
             */
            SettingsPage::renderActionBar(
                'ctp-run-sync',
                __('Jetzt synchronisieren', 'churchtools-plugin'),
                __('Holt die Termine aller aktiven Kalender sofort, unabhängig vom Intervall.', 'churchtools-plugin')
            );
            ?>
            <?php if ($lastError !== null) : ?>
                <div class="notice notice-error inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the sync last failed, 2: error message */
                            esc_html__('Letzter Sync-Fehler (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date($facts['date_format'], $lastError['time'])),
                            // Api\Client::excerpt() kuerzt neue Meldungen bereits an
                            // der Quelle; in ctp_last_sync_error kann aus der Zeit
                            // davor aber noch eine komplette HTML-Fehlerseite
                            // liegen, und die schoebe diesen Kasten ueber die
                            // ganze Seite.
                            esc_html(wp_html_excerpt($lastError['message'], 600, '…'))
                        );
                        ?>
                    </p>
                </div>
            <?php elseif (!$facts['configured']) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: link to "Einstellungen → Verbindung" */
                            esc_html__('Noch keine Instanz/API-Key hinterlegt. Unter %s eintragen.', 'churchtools-plugin'),
                            '<a href="' . esc_url(SettingsPage::tabUrl('connection')) . '">'
                                . esc_html__('Einstellungen → Verbindung', 'churchtools-plugin') . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php elseif ($health !== null) : ?>
                <div class="notice notice-<?php echo esc_attr($health['type']); ?> inline">
                    <p><?php echo esc_html($health['message']); ?></p>
                </div>
            <?php endif; ?>
            <?php $calendarError = SyncEngine::getLastCalendarError(); ?>
            <?php if ($calendarError !== null) : ?>
                <?php
                /*
                 * Eigener Kasten neben dem Sync-Fehler darueber, nicht statt
                 * seiner: Der Kalenderabgleich scheitert unabhaengig vom
                 * Terminabgleich und haelt ihn nicht auf (siehe
                 * SyncEngine::refreshCalendarList()). Bisher stand dieser
                 * Befund nur im Tab „Kalender“ - wer hier nachsah, warum
                 * nichts synchronisiert wird, fand eine Seite ohne jeden
                 * Fehler, waehrend die Kalenderliste in Wahrheit seit Stunden
                 * nicht mehr geholt werden konnte.
                 */
                ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the calendar refresh last failed, 2: error message */
                            esc_html__('Der Kalenderabgleich ist zuletzt fehlgeschlagen (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date($facts['date_format'], $calendarError['time'])),
                            esc_html(wp_html_excerpt($calendarError['message'], 600, '…'))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php SettingsPage::renderImageWarning(SyncEngine::getImageWarning(), 'events'); ?>
            <?php
            SettingsPage::renderOverviewRows([
                [
                    'label' => __('Aktive Kalender', 'churchtools-plugin'),
                    'value' => sprintf(
                        /* translators: 1: number of enabled calendars, 2: total number of known calendars */
                        __('%1$d von %2$d', 'churchtools-plugin'),
                        $facts['enabled_count'],
                        $facts['calendar_count']
                    ),
                ],
                [
                    'label' => __('Gespeicherte Termine', 'churchtools-plugin'),
                    'value' => (string) (new EventRepository())->count(),
                ],
                [
                    'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                    'value' => $facts['last_sync_label'],
                ],
                [
                    'label' => sprintf(
                        /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                        __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                        SettingsPage::syncIntervalLabels()[$settings['sync_interval']] ?? $settings['sync_interval']
                    ),
                    'value' => $facts['next_sync_label'],
                ],
            ]);

            /*
             * Die naechsten Handgriffe als Linkzeile statt als Fliesstext: von
             * der Uebersicht aus fuehrt jeder Weg ohnehin in einen anderen Tab,
             * und der Weg dorthin war bisher nur der Tab-Reiter selbst. Fuer
             * Termine und Gruppen dieselben Ziele in derselben Reihenfolge wie
             * ihre Reiter.
             */
            SettingsPage::renderQuicklinks([
                ['url' => EventsTab::eventsOverviewUrl(), 'icon' => 'list-view', 'label' => __('Gespeicherte Termine ansehen', 'churchtools-plugin')],
                ['url' => SettingsPage::tabUrl('calendars'), 'icon' => 'calendar-alt', 'label' => __('Kalender auswählen', 'churchtools-plugin')],
                ['url' => SettingsPage::tabUrl('sync'), 'icon' => 'update', 'label' => __('Sync-Einstellungen', 'churchtools-plugin')],
                ['url' => SettingsPage::tabUrl('embed'), 'icon' => 'editor-code', 'label' => __('Termine einbinden', 'churchtools-plugin')],
            ]);
            ?>
        </div>

        <?php GroupsTab::renderOverviewPanel(); ?>

        <?php PostsTab::renderOverviewPanel(); ?>

        <?php MajorVersionNotice::renderOverviewPanel(); ?>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Version', 'churchtools-plugin'); ?></h2>
            <table class="widefat striped ctp-borderless ctp-keyvalue-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Installiert', 'churchtools-plugin'); ?></th>
                        <td><?php echo esc_html(CTP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Verfügbar', 'churchtools-plugin'); ?></th>
                        <td>
                            <?php if ($update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>')) : ?>
                                <?php
                                printf(
                                    /* translators: %s: newer version number available via GitHub */
                                    esc_html__('%s (Update über die Plugins-Übersicht einspielen)', 'churchtools-plugin'),
                                    esc_html($update['version'])
                                );
                                ?>
                            <?php else : ?>
                                <?php esc_html_e('aktuell', 'churchtools-plugin'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php $latest = UpdatesTab::changelogReleases(1, 5); ?>
            <?php if ($latest !== []) : ?>
                <h3><?php esc_html_e('Letzte Änderungen', 'churchtools-plugin'); ?></h3>
                <?php // Nur die Kurzfassung - die Erklaerung dazu steht unter Einstellungen → Updates. ?>
                <ul class="ctp-changelog-excerpt">
                    <?php foreach ($latest[0]['items'] as $item) : ?>
                        <li><?php echo esc_html($item['lead']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="description">
                    <a href="<?php echo esc_url(SettingsPage::tabUrl('updates')); ?>">
                        <?php esc_html_e('Alle Änderungen unter „Einstellungen → Updates“', 'churchtools-plugin'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
