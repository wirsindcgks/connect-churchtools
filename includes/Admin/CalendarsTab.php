<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Settings;
use ChurchToolsPlugin\Sync\CalendarList;
use ChurchToolsPlugin\Sync\SyncEngine;

/**
 * Der Reiter „Kalender" im Bereich „Events": Kalenderkacheln mit Schalter,
 * Farbe, Standardbild und Terminzahlen, dazu Suche und Sammelschalter.
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan). Gespeichert wird weiter ueber
 * SettingsPage::sanitizeSettings() (sanitizeCalendars()).
 */
final class CalendarsTab
{
    /**
     * Der Tab „Kalender“ - eine Kachelliste statt der bisherigen Tabelle.
     *
     * Vorher steckte die ganze Kalenderauswahl als *ein* Settings-API-Feld in
     * einer .form-table. Das kostete links rund 200 Pixel fuer eine
     * Beschriftung („Kalender“), die nur die Ueberschrift darueber
     * wiederholte, und presste vier Spalten - Haken, Name, Farbe, Standardbild -
     * in den Rest. Die Farbe war ein 36px-Kaestchen zwischen zwei
     * Bedienelementen, und woran man einen Kalender ueberhaupt erkennt,
     * naemlich wie viele Termine er liefert, stand nirgends.
     *
     * Die Kachelliste dreht das um:
     *   - Die Kalenderfarbe ist der farbige Balken der Kachel, also das, was
     *     man zuerst sieht - nicht mehr ein Kaestchen in Spalte drei.
     *   - Jede Kachel nennt ihre Termine (kommend/gesamt, siehe
     *     EventRepository::countsByCalendar()). Ein Kalender, der seit Monaten
     *     nichts liefert, faellt damit auf.
     *   - Inaktive Kalender sind sichtbar gedimmt, statt sich nur durch einen
     *     leeren Haken ganz links von den aktiven zu unterscheiden.
     *   - Suche und „Alle aktivieren/deaktivieren“ machen die Liste auch bei
     *     zwei Dutzend Kalendern noch bedienbar.
     *   - Jede Kachel liefert den fertigen Shortcode fuer genau diesen
     *     Kalender zum Kopieren - dieselbe Schaltflaeche wie in der
     *     Shortcode-Referenz im Tab „Design“.
     *
     * Kein Settings-API-Abschnitt mehr, sondern direkt gerendert: die
     * Feldnamen sind unveraendert (ctp_settings[calendars][ID][...]), also
     * greifen settings_fields() und sanitizeCalendars() genau wie zuvor.
     */
    public static function render(): void
    {
        $calendars = Settings::get()['calendars'];
        uasort($calendars, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $counts = self::calendarEventCounts();
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
            <?php settings_fields(SettingsPage::PAGE_SLUG); ?>
            <h2><?php esc_html_e('Kalenderauswahl', 'churchtools-plugin'); ?></h2>
            <?php
            SettingsPage::renderActionBar(
                'ctp-fetch-calendars',
                __('Kalender von ChurchTools laden', 'churchtools-plugin'),
                __('Jede Synchronisation gleicht die Liste automatisch mit ab – dieser Button holt sie sofort. Eingestellte Farben und Standardbilder bleiben dabei erhalten.', 'churchtools-plugin')
            );
            ?>

            <?php $calendarError = SyncEngine::getLastCalendarError(); ?>
            <?php if ($calendarError !== null) : ?>
                <?php
                /*
                 * Der Sync zieht die Kalenderliste inzwischen bei jedem Lauf
                 * mit nach (siehe SyncEngine::refreshCalendarList()). Scheitert
                 * das, laeuft der Terminabgleich trotzdem weiter - dieser
                 * Hinweis ist die einzige Stelle, an der es auffaellt.
                 */
                ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: date/time the calendar refresh last failed, 2: error message */
                            esc_html__('Der automatische Kalenderabgleich ist zuletzt fehlgeschlagen (%1$s): %2$s', 'churchtools-plugin'),
                            esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $calendarError['time'])),
                            esc_html(wp_html_excerpt($calendarError['message'], 400, '…'))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php $nonPublic = CalendarList::nonPublicEnabled(); ?>
            <?php if ($nonPublic !== []) : ?>
                <?php // Zahl-neutral formuliert: bin/make-pot.php kann keine Plurale (_n). ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: comma-separated list of calendar names */
                            esc_html__('Hier aktiv, in ChurchTools aber als Gruppen- oder persönlicher Kalender statt als Gemeindekalender geführt: %s. Die Termine erscheinen trotzdem auf der Website – entweder hier abwählen oder den Kalender in ChurchTools als Gemeindekalender führen.', 'churchtools-plugin'),
                            esc_html(implode(', ', $nonPublic))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($calendars === []) : ?>
                <p class="ctp-empty-state"><?php esc_html_e('Noch keine Kalender geladen.', 'churchtools-plugin'); ?></p>
            <?php else : ?>
                <div class="ctp-toolbar ctp-toolbar--secondary">
                    <label class="screen-reader-text" for="ctp-calendar-search">
                        <?php esc_html_e('Kalender filtern', 'churchtools-plugin'); ?>
                    </label>
                    <input
                        type="search"
                        id="ctp-calendar-search"
                        class="ctp-calendar-search"
                        placeholder="<?php esc_attr_e('Kalender filtern …', 'churchtools-plugin'); ?>"
                    />
                    <span class="ctp-toolbar__spacer"></span>
                    <button type="button" class="button ctp-calendar-bulk" data-enable="1">
                        <?php esc_html_e('Alle aktivieren', 'churchtools-plugin'); ?>
                    </button>
                    <button type="button" class="button ctp-calendar-bulk" data-enable="0">
                        <?php esc_html_e('Alle deaktivieren', 'churchtools-plugin'); ?>
                    </button>
                </div>

                <ul class="ctp-calendar-grid" id="ctp-calendar-grid">
                    <?php foreach ($calendars as $id => $calendar) : ?>
                        <?php self::renderCalendarCard((int) $id, $calendar, $counts[(int) $id] ?? ['total' => 0, 'upcoming' => 0]); ?>
                    <?php endforeach; ?>
                </ul>
                <p class="ctp-empty-state" id="ctp-calendar-no-match" hidden>
                    <?php esc_html_e('Kein Kalender passt zu diesem Suchbegriff.', 'churchtools-plugin'); ?>
                </p>

                <p class="description">
                    <?php esc_html_e('Nur aktive Kalender werden synchronisiert. Termine eines gerade deaktivierten Kalenders entfernt der nächste Sync.', 'churchtools-plugin'); ?>
                </p>
                <p class="description">
                    <?php esc_html_e('Das Standardbild erscheint überall dort, wo ein Termin dieses Kalenders kein eigenes Bild mitbringt.', 'churchtools-plugin'); ?>
                </p>
            <?php endif; ?>

            </div>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Eine Kalenderkachel. Der farbige Balken oben traegt die Kalenderfarbe,
     * damit sie ohne Umweg ueber ein Bedienelement sichtbar ist; das
     * Farbfeld darunter ist dasselbe Paar aus Farbwaehler und Hex-Feld wie
     * zuvor (siehe .ctp-color-field und den geteilten Inline-Skript-Block in
     * renderPage()).
     *
     * @param array{total: int, upcoming: int} $count
     */
    private static function renderCalendarCard(int $id, array $calendar, array $count): void
    {
        $fieldBase = sprintf('%s[calendars][%d]', SettingsPage::OPTION_KEY, $id);
        $imageId = (int) $calendar['default_image_id'];
        $imageUrl = $imageId ? (string) wp_get_attachment_image_url($imageId, 'thumbnail') : '';
        $enabled = !empty($calendar['enabled']);
        $shortcode = sprintf('[ctp_events calendar="%s"]', $calendar['name']);
        $checkboxId = sprintf('ctp-calendar-enabled-%d', $id);
        ?>
        <li
            class="ctp-calendar-card<?php echo $enabled ? '' : ' is-disabled'; ?>"
            data-name="<?php echo esc_attr(mb_strtolower($calendar['name'])); ?>"
            data-id="<?php echo esc_attr((string) $id); ?>"
        >
            <span class="ctp-calendar-card__bar" style="background-color:<?php echo esc_attr($calendar['color']); ?>" aria-hidden="true"></span>

            <div class="ctp-calendar-card__head">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($checkboxId); ?>"
                    class="ctp-calendar-enabled"
                    name="<?php echo esc_attr($fieldBase); ?>[enabled]"
                    value="1"
                    <?php checked($enabled); ?>
                />
                <label class="ctp-calendar-card__name" for="<?php echo esc_attr($checkboxId); ?>">
                    <?php echo esc_html($calendar['name']); ?>
                </label>
            </div>

            <p class="ctp-calendar-card__facts">
                <code class="ctp-muted-code">ID <?php echo (int) $id; ?></code>
                <span aria-hidden="true">·</span>
                <?php
                printf(
                    /* translators: 1: number of upcoming events, 2: total number of stored events */
                    esc_html__('%1$d kommend von %2$d gespeicherten Terminen', 'churchtools-plugin'),
                    (int) $count['upcoming'],
                    (int) $count['total']
                );
                ?>
            </p>

            <div class="ctp-calendar-card__row ctp-color-field">
                <span class="ctp-calendar-card__row-label"><?php esc_html_e('Farbe', 'churchtools-plugin'); ?></span>
                <?php
                // Farbwaehler und Hex-Feld sind ein Bedienelement: nur das
                // <input type="color"> traegt einen Namen und wird abgeschickt,
                // das Textfeld ist ein Spiegel, den das Inline-Skript in
                // renderPage() in beide Richtungen nachfuehrt. Wer nach einem
                // Styleguide arbeitet, hat den Hex-Code, und ein nativer
                // Farbwaehler bietet keine Moeglichkeit, ihn einzutippen.
                ?>
                <input
                    type="color"
                    class="ctp-color-input"
                    name="<?php echo esc_attr($fieldBase); ?>[color]"
                    value="<?php echo esc_attr($calendar['color']); ?>"
                    aria-label="<?php esc_attr_e('Farbe wählen', 'churchtools-plugin'); ?>"
                />
                <input
                    type="text"
                    class="ctp-color-hex"
                    value="<?php echo esc_attr($calendar['color']); ?>"
                    maxlength="7"
                    spellcheck="false"
                    autocomplete="off"
                    aria-label="<?php esc_attr_e('Farbe als Hex-Code', 'churchtools-plugin'); ?>"
                />
                <button
                    type="button"
                    class="button-link ctp-color-reset"
                    data-default-color="<?php echo esc_attr($calendar['default_color'] ?? $calendar['color']); ?>"
                    title="<?php esc_attr_e('Auf die Farbe zurücksetzen, die dieser Kalender in ChurchTools hat', 'churchtools-plugin'); ?>"
                >
                    <?php esc_html_e('Zurücksetzen', 'churchtools-plugin'); ?>
                </button>
            </div>

            <div class="ctp-calendar-card__row ctp-image-field">
                <span class="ctp-calendar-card__row-label"><?php esc_html_e('Standardbild', 'churchtools-plugin'); ?></span>
                <input type="hidden" class="ctp-image-id" name="<?php echo esc_attr($fieldBase); ?>[default_image_id]" value="<?php echo esc_attr((string) $imageId); ?>" />
                <img class="ctp-image-preview" src="<?php echo esc_url($imageUrl); ?>" alt="" <?php echo $imageUrl ? '' : 'hidden'; ?> />
                <button type="button" class="button button-small ctp-image-select">
                    <?php echo $imageUrl ? esc_html__('Ersetzen', 'churchtools-plugin') : esc_html__('Bild wählen', 'churchtools-plugin'); ?>
                </button>
                <button type="button" class="button-link ctp-image-remove" <?php echo $imageUrl ? '' : 'hidden'; ?>>
                    <?php esc_html_e('Entfernen', 'churchtools-plugin'); ?>
                </button>
            </div>

            <div class="ctp-calendar-card__row ctp-calendar-card__row--shortcode">
                <code><?php echo esc_html($shortcode); ?></code>
                <button
                    type="button"
                    class="button button-small ctp-copy-shortcode"
                    data-shortcode="<?php echo esc_attr($shortcode); ?>"
                    title="<?php esc_attr_e('Shortcode für genau diesen Kalender kopieren', 'churchtools-plugin'); ?>"
                >
                    <?php esc_html_e('Kopieren', 'churchtools-plugin'); ?>
                </button>
            </div>
        </li>
        <?php
    }

    /**
     * Termine je Kalender, einmal pro Seitenaufbau. Der Tab „Kalender“ braucht
     * dieselbe Auskunft zweimal - fuer die Statuszeile oben und fuer die Zahl
     * auf jeder Kachel -, und ohne diesen Zwischenspeicher liefe dafuer
     * zweimal dieselbe Abfrage.
     *
     * @return array<int, array{total: int, upcoming: int}>
     */
    public static function calendarEventCounts(): array
    {
        static $counts = null;

        if ($counts === null) {
            $counts = (new EventRepository())->countsByCalendar();
        }

        return $counts;
    }
}
