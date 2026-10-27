<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Settings;

/**
 * Der Reiter „Einbinden" im Bereich „Events": die drei Wege (Shortcode,
 * Block, WPBakery-Element) und die Referenz der Shortcode-Attribute.
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan). Das Gegenstueck fuer Gruppen ist
 * GroupsTab::renderEmbed().
 */
final class EmbedTab
{
    /**
     * Der Tab „Einbinden": die Shortcode-Referenz, die bis 1.5.2 unter dem
     * Design-Tab hing.
     *
     * Sie gehoerte dort nie hin. Man liest sie, waehrend man eine *Seite*
     * baut, nicht waehrend man das Aussehen einstellt — und sie war das
     * laengste Stueck des ohnehin laengsten Bildschirms, sodass die
     * Design-Einstellungen darueber im Scrollen verschwanden. Ein eigener Tab
     * ist auffindbar (anders als WordPress' eingeklappte „Hilfe" oben rechts)
     * und nimmt dem Design-Tab seine halbe Hoehe.
     */
    public static function render(): void
    {
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Drei Wege, dieselbe Darstellung', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Termine lassen sich per Shortcode, über den Gutenberg-Block „ChurchTools Events“ oder über das WPBakery-Element „ChurchTools Events“ einbinden. Alle drei rendern dasselbe – was unter „Einstellungen → Design“ eingestellt ist, gilt für jeden von ihnen, ohne ein weiteres Attribut. Die Optionen unten überschreiben diese Einstellungen nur für den einen Baustein, in dem sie stehen.', 'churchtools-plugin'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Block und WPBakery-Element bieten dieselben Optionen in ihrer eigenen Seitenleiste an. Zu eigenen Theme-Templates siehe readme.txt.', 'churchtools-plugin'); ?>
            </p>
        </div>
        <?php self::renderShortcodeReference(); ?>
        <?php
    }

    private static function renderShortcodeReference(): void
    {
        $calendars = Settings::get()['calendars'];
        $enabledIds = Settings::getEnabledCalendarIds();
        $exampleCalendar = '';
        if ($enabledIds !== []) {
            $firstId = $enabledIds[0];
            $exampleCalendar = $calendars[$firstId]['name'] ?? (string) $firstId;
        }

        $examples = [
            [
                'label' => __('Liste', 'churchtools-plugin'),
                'code' => $exampleCalendar !== ''
                    ? sprintf('[ctp_events calendar="%s" layout="list"]', $exampleCalendar)
                    : '[ctp_events layout="list"]',
            ],
            [
                'label' => __('Liste mit Filter, Suche & Monatstrennern', 'churchtools-plugin'),
                'code' => '[ctp_events layout="list" filter="1" search="1" month_dividers="1"]',
            ],
            [
                'label' => __('Raster mit Eventfinder und Suche', 'churchtools-plugin'),
                'code' => '[ctp_events layout="grid" finder="1" search="1"]',
            ],
            [
                'label' => __('Raster', 'churchtools-plugin'),
                'code' => '[ctp_events layout="grid" columns="3"]',
            ],
            [
                'label' => __('Kurzer Teaser ohne Nachladen', 'churchtools-plugin'),
                'code' => '[ctp_events layout="grid" limit="3" paging="0"]',
            ],
            [
                'label' => __('Nächster Termin', 'churchtools-plugin'),
                'code' => '[ctp_events layout="upcoming" limit="4"]',
            ],
        ];
        ?>
        <?php // Beispiele zuerst: Wer hier landet, will meistens etwas kopieren
        // und nicht nachschlagen. Die vollstaendige Attributliste steht im
        // Panel darunter, fuer die selteneren Faelle. ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Beispiele zum Kopieren', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Fertige Shortcodes für die häufigsten Fälle – der erste aktive Kalender dieser Instanz ist bereits eingesetzt.', 'churchtools-plugin'); ?>
            </p>
            <ul class="ctp-shortcode-examples">
                <?php foreach ($examples as $example) : ?>
                    <li>
                        <span class="ctp-shortcode-label"><?php echo esc_html($example['label']); ?></span>
                        <code><?php echo esc_html($example['code']); ?></code>
                        <button type="button" class="button button-small ctp-copy-shortcode" data-shortcode="<?php echo esc_attr($example['code']); ?>">
                            <?php esc_html_e('Kopieren', 'churchtools-plugin'); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="ctp-panel">
            <h2><?php esc_html_e('Alle Attribute', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Jedes Attribut ist optional. Weggelassen gilt der Standard aus der rechten Spalte – und wo dort auf „Einstellungen → Design“ verwiesen wird, die dortige Einstellung.', 'churchtools-plugin'); ?>
            </p>

            <table class="widefat striped ctp-borderless">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Attribut', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Beschreibung', 'churchtools-plugin'); ?></th>
                        <th><?php esc_html_e('Standard', 'churchtools-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>calendar</code></td>
                        <td><?php esc_html_e('Kommagetrennte Kalender-IDs und/oder -Namen. Leer = alle aktiven Kalender.', 'churchtools-plugin'); ?></td>
                        <td>&ndash;</td>
                    </tr>
                    <tr>
                        <td><code>layout</code></td>
                        <td><code>list</code> &middot; <code>grid</code> &middot; <code>upcoming</code></td>
                        <td><code>list</code></td>
                    </tr>
                    <tr>
                        <td><code>limit</code></td>
                        <td>
                            <?php esc_html_e('Obergrenze für die Anzahl Termine. 0 = unbegrenzt; bei list/grid entscheidet der Zeitraum, wie viel angezeigt wird, und limit wirkt nur als Deckel pro Nachlade-Schritt. Bei upcoming die Gesamtzahl inkl. Hero-Kachel (0 = 10).', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>months</code></td>
                        <td>
                            <?php
                            printf(
                                /* translators: %d: globally configured number of months per page. */
                                esc_html__('Zeitraum pro Seite in Monaten (nur list/grid). 0 = die Einstellung unter „Einstellungen → Design“ (aktuell %d).', 'churchtools-plugin'),
                                (int) Settings::get()['paging_months']
                            );
                            ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>paging</code></td>
                        <td><?php esc_html_e('Button „Weitere Termine laden“ anzeigen (nur list/grid). 0 = nur der erste Zeitraum, ohne Nachladen.', 'churchtools-plugin'); ?></td>
                        <td><code>1</code></td>
                    </tr>
                    <tr>
                        <td><code>columns</code></td>
                        <td><?php esc_html_e('Nur bei Grid-Layout: höchstens so viele Spalten (2–6), wie in den Inhaltsbereich passen – je Kachel mindestens 240px.', 'churchtools-plugin'); ?></td>
                        <td><code>3</code></td>
                    </tr>
                    <tr>
                        <td><code>click</code></td>
                        <td>
                            <code>default</code> &middot; <code>none</code> &middot; <code>popup</code> &middot; <code>page</code>
                            &ndash; <?php esc_html_e('überschreibt das Klickverhalten aus „Einstellungen → Design“ nur für diesen Shortcode', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>default</code></td>
                    </tr>
                    <tr>
                        <td><code>filter</code></td>
                        <td>
                            <?php esc_html_e('Kalenderfilter-Dropdown anzeigen (nur list/grid, nur bei ≥2 Kalendern im Ergebnis)', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>search</code></td>
                        <td><?php esc_html_e('Freitext-Suchleiste anzeigen (nur list/grid, filtert Titel/Untertitel/Ort)', 'churchtools-plugin'); ?></td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>month_dividers</code></td>
                        <td><?php esc_html_e('Termine nach Monat gruppiert darstellen (nur list/grid)', 'churchtools-plugin'); ?></td>
                        <td><code>0</code></td>
                    </tr>
                    <tr>
                        <td><code>finder</code></td>
                        <td>
                            <?php esc_html_e('Eventfinder: Knöpfe für Kalender und Zeitraum (nur list/grid); ersetzt filter. Mit search steht das Suchfeld im Eventfinder. Früher eventfinder – der Name gilt weiter.', 'churchtools-plugin'); ?>
                        </td>
                        <td><code>0</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
