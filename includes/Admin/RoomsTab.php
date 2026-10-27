<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Settings;
use ChurchToolsPlugin\Sync\ResourceList;
use ChurchToolsPlugin\Sync\RoomLookup;

/**
 * Der Reiter „Räume" im Bereich „Events": welche Raumbuchungen zur
 * Ortsangabe eines Termins werden, und die Zeile, wie viele Termine eine
 * Ortsangabe haben.
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan). Gespeichert wird weiter ueber
 * SettingsPage::sanitizeSettings(), die Felder gehoeren zur Option
 * `ctp_settings`.
 */
final class RoomsTab
{
    /**
     * Der Tab „Raeume". Eine Liste mit Haken, sonst nichts - und das ist die
     * ganze Bedienung dieser Funktion.
     *
     * Warum es keine Reihenfolge gibt: Der verworfene Gegenentwurf war eine
     * Prioritaetenliste, aus der bei mehreren gebuchten Raeumen der oberste
     * gewinnt. Sie erreichte mehr Termine, behauptete aber sichtbar Falsches -
     * ein Ferienprogramm mit zehn gebuchten Raeumen erschien unter dem Namen
     * eines Nebenraums, und dieselbe Serie zeigte von Woche zu Woche einen
     * anderen Raum, weil das gebuchte Buendel wechselt. Ein Haken allein sagt
     * genug: „Dieser Raum ist es wert, oeffentlich genannt zu werden."
     */
    public static function render(): void
    {
        $resources = Settings::get()['resources'] ?? [];

        // ChurchTools' eigene Ordnung: grosse Raeume oben, Testressourcen unten.
        uasort($resources, static function (array $a, array $b): int {
            return [$a['sort_key'] ?? 0, $a['name']] <=> [$b['sort_key'] ?? 0, $b['name']];
        });

        $fetched = (string) get_option(ResourceList::FETCHED_OPTION, '');
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
                <?php settings_fields(SettingsPage::PAGE_SLUG); ?>
                <h2><?php esc_html_e('Räume in der Ortsangabe', 'churchtools-plugin'); ?></h2>

                <p class="description">
                    <?php esc_html_e('ChurchTools führt am Termin einen Ort und daneben die Räume, die dafür gebucht werden. Ist am Termin ein Ort eingetragen, gilt dieser – eine Raumbuchung kann aus einer Vorlage stammen oder versehentlich gesetzt sein. Nur wo kein Ort eingetragen ist, springen die angehakten Räume ein: als Ortsangabe, sobald für einen Termin genau einer davon bestätigt gebucht ist. Sind es mehrere, bleibt die Angabe aus – eine Aufzählung aller gebuchten Räume ist keine Ortsangabe.', 'churchtools-plugin'); ?>
                </p>

                <p class="description">
                    <?php esc_html_e('Für Suchmaschinen und Kalender-Apps zählt zusätzlich das Feld „Ort“ an der Ressource in ChurchTools: Trägt es denselben Gebäudenamen wie die Anschrift der Gemeinde, erscheint diese Anschrift samt Koordinaten unsichtbar neben dem Raumnamen. Ein Raumname allein verortet nichts.', 'churchtools-plugin'); ?>
                </p>

                <?php
                SettingsPage::renderActionBar(
                    'ctp-fetch-resources',
                    __('Räume von ChurchTools laden', 'churchtools-plugin'),
                    __('Jede Synchronisation gleicht die Liste automatisch mit ab – dieser Button holt sie sofort. Die Haken bleiben dabei erhalten.', 'churchtools-plugin')
                );
                ?>

                <?php
                /*
                 * Die Raumangabe entsteht beim Sync und steht danach als Wert in
                 * der Termintabelle - anders als eine Kalenderfarbe, die bei
                 * jedem Seitenaufruf neu gerendert wird. Gemeldet wurde das als
                 * „der Wechsel zeigt nicht direkt zu greifen", und der zweite
                 * Anlauf (ein Hinweis plus ein Knopf oben) war auch noch keine
                 * gute Antwort: Nach dem Speichern landet man am Seitenanfang
                 * und musste den Knopf erst suchen.
                 *
                 * Jetzt uebernimmt die Seite selbst. Kommt sie mit
                 * `settings-updated` zurueck - also direkt nach dem Speichern -,
                 * laeuft der Abgleich von allein an, und darunter steht, was
                 * dabei herausgekommen ist. Der Knopf bleibt fuer den Fall, dass
                 * jemand ohne Aenderung nachsehen will.
                 */
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur die Frage „kommt die Seite von einem Speichern?"; WordPress haengt den Parameter selbst an, und ausgeloest wird davon ein Lesevorgang, der auch per Knopf jederzeit erlaubt ist.
                $justSaved = isset($_GET['settings-updated']);
                ?>
                <div class="ctp-rooms-apply" data-auto="<?php echo $justSaved ? '1' : '0'; ?>">
                    <?php
                    SettingsPage::renderActionBar(
                        'ctp-sync-rooms',
                        __('Änderungen übernehmen', 'churchtools-plugin'),
                        __('Der Ort wird beim Abgleich zum Termin geschrieben, nicht beim Anzeigen – eine Änderung hier wird deshalb erst mit dem nächsten Abgleich sichtbar. Nach dem Speichern startet er von selbst.', 'churchtools-plugin')
                    );
                    ?>
                    <p class="description" id="ctp-rooms-summary"><?php echo esc_html(self::locationSummary()); ?></p>
                </div>

                <?php if ($resources === []) : ?>
                    <div class="notice notice-info inline">
                        <p>
                            <?php esc_html_e('Es sind keine Räume bekannt. Entweder verwendet die Instanz keine Ressourcen, oder der API-Key ist nicht für sie freigegeben – die Freigabe heißt in ChurchTools „Ressource sehen“ und ist von der allgemeinen Berechtigung für das Ressourcen-Modul getrennt.', 'churchtools-plugin'); ?>
                        </p>
                    </div>
                <?php else : ?>
                    <?php if ($fetched !== '') : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: date and time the room list was last fetched */
                                esc_html__('Zuletzt geladen: %s', 'churchtools-plugin'),
                                esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $fetched))
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <table class="widefat striped ctp-rooms-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Als Ortsangabe zeigen', 'churchtools-plugin'); ?></th>
                                <th scope="col"><?php esc_html_e('Raum', 'churchtools-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resources as $id => $resource) : ?>
                                <tr>
                                    <td>
                                        <?php
                                        /*
                                         * Verstecktes Feld vor jedem Kaestchen: Ein
                                         * leeres Kaestchen sendet gar nichts, und ohne
                                         * diese Null waere das Abwaehlen des letzten
                                         * Raums nicht speicherbar - sanitizeSettings()
                                         * saehe dann keinen `resources`-Schluessel und
                                         * truege die alten Haken unveraendert weiter.
                                         */
                                        ?>
                                        <input
                                            type="hidden"
                                            name="<?php echo esc_attr(SettingsPage::OPTION_KEY); ?>[resources][<?php echo esc_attr((string) $id); ?>][enabled]"
                                            value="0"
                                        >
                                        <input
                                            type="checkbox"
                                            id="ctp-resource-<?php echo esc_attr((string) $id); ?>"
                                            name="<?php echo esc_attr(SettingsPage::OPTION_KEY); ?>[resources][<?php echo esc_attr((string) $id); ?>][enabled]"
                                            value="1"
                                            <?php checked(!empty($resource['enabled'])); ?>
                                        >
                                    </td>
                                    <td>
                                        <label for="ctp-resource-<?php echo esc_attr((string) $id); ?>">
                                            <?php echo esc_html($resource['name']); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="description">
                        <?php esc_html_e('Sparsam anhaken: Je mehr Räume ausgewählt sind, desto häufiger sind mehrere davon gleichzeitig gebucht – und desto öfter bleibt die Ortsangabe deshalb leer. Am Anfang sind die wenigen Räume richtig, die für sich allein einen Termin verorten.', 'churchtools-plugin'); ?>
                    </p>

                    <h3><?php esc_html_e('Wenn für einen Termin mehrere Räume gebucht sind', 'churchtools-plugin'); ?></h3>

                    <?php $mode = ResourceList::mode(); ?>
                    <fieldset>
                        <p>
                            <label>
                                <input
                                    type="radio"
                                    name="<?php echo esc_attr(SettingsPage::OPTION_KEY); ?>[rooms_mode]"
                                    value="<?php echo esc_attr(RoomLookup::MODE_ALL); ?>"
                                    <?php checked($mode, RoomLookup::MODE_ALL); ?>
                                >
                                <?php esc_html_e('Alle ausgewählten Räume nennen, durch Komma getrennt', 'churchtools-plugin'); ?>
                            </label><br>
                            <span class="description">
                                <?php esc_html_e('Zeigt, was ChurchTools liefert. Die Zeile wächst mit der Auswahl – bei wenigen ausgewählten Räumen bleibt sie kurz, bei vielen kann aus der Ortsangabe eine Aufzählung werden.', 'churchtools-plugin'); ?>
                            </span>
                        </p>

                        <p>
                            <label>
                                <input
                                    type="radio"
                                    name="<?php echo esc_attr(SettingsPage::OPTION_KEY); ?>[rooms_mode]"
                                    value="<?php echo esc_attr(RoomLookup::MODE_SINGLE); ?>"
                                    <?php checked($mode, RoomLookup::MODE_SINGLE); ?>
                                >
                                <?php esc_html_e('Nur nennen, wenn genau ein ausgewählter Raum gebucht ist', 'churchtools-plugin'); ?>
                            </label><br>
                            <span class="description">
                                <?php esc_html_e('Nicht ausgewählte Räume zählen dabei nicht mit: Sind daneben weitere belegt, erscheint trotzdem der eine ausgewählte.', 'churchtools-plugin'); ?>
                            </span>
                        </p>

                        <p>
                            <label>
                                <input
                                    type="radio"
                                    name="<?php echo esc_attr(SettingsPage::OPTION_KEY); ?>[rooms_mode]"
                                    value="<?php echo esc_attr(RoomLookup::MODE_EXCLUSIVE); ?>"
                                    <?php checked($mode, RoomLookup::MODE_EXCLUSIVE); ?>
                                >
                                <?php esc_html_e('Nur nennen, wenn für den Termin sonst kein weiterer Raum gebucht ist', 'churchtools-plugin'); ?>
                            </label><br>
                            <span class="description">
                                <?php esc_html_e('Die vorsichtigste Einstellung: Jede weitere Buchung lässt die Ortsangabe aus, auch die eines nicht ausgewählten Raums.', 'churchtools-plugin'); ?>
                            </span>
                        </p>
                    </fieldset>
                <?php endif; ?>
            </div>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Ein Satz statt zweier Zahlen: Er beantwortet die Frage, mit der jemand den
     * Tab „Raeume" verlaesst - hat die Auswahl etwas bewirkt? Zahl-neutral
     * formuliert, weil bin/make-pot.php keine Plurale kann.
     */
    public static function locationSummary(): string
    {
        $repository = new EventRepository();
        $gesamt = $repository->count();

        if ($gesamt === 0) {
            return __('Es sind noch keine Termine gespeichert.', 'churchtools-plugin');
        }

        return sprintf(
            /* translators: 1: number of events with a location, 2: total number of stored events */
            __('Ortsangabe vorhanden: %1$d von %2$d gespeicherten Terminen.', 'churchtools-plugin'),
            $repository->countWithLocation(),
            $gesamt
        );
    }
}
