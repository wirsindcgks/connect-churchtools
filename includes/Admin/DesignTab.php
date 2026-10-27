<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Frontend\DesignPreset;
use ChurchToolsPlugin\Frontend\DetailDesign;
use ChurchToolsPlugin\Frontend\EventWindow;
use ChurchToolsPlugin\Frontend\Icons;
use ChurchToolsPlugin\Frontend\LiveBadge;
use ChurchToolsPlugin\Settings;

/**
 * Der Reiter „Design" unter „Einstellungen": die vier Bereiche Stil,
 * Kachel, Detailansicht und Listen, ihre Felder und die beiden
 * Live-Vorschauen (getrieben von assets/js/admin-design.js).
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan). Gespeichert wird weiter ueber
 * SettingsPage::sanitizeSettings(), die Felder gehoeren zur Option
 * `ctp_settings`.
 */
final class DesignTab
{
    /**
     * Abschnitte und Felder der vier Design-Bereiche, aufgerufen aus
     * SettingsPage::registerSettings().
     */
    public static function registerFields(): void
    {
        /*
         * Vier Gruppen statt der bisherigen drei plus Sammelbecken. Bis 1.5.2
         * gab es einen Abschnitt „Globale Einstellungen", in dem acht Felder
         * lagen, die miteinander wenig zu tun hatten: das Klickverhalten neben
         * dem Eckenstil neben dem Zeitraum pro Seite. Was zusammengehoert,
         * stand auseinander — die Felder der Kachel unter der Detailansicht,
         * die Adresse der Terminseite drei Felder von der Detailansicht
         * entfernt, zu der sie gehoert.
         *
         * Sortiert ist jetzt nach der Frage, die der Betreiber gerade
         * beantwortet:
         *   1. Wie soll es grundsaetzlich aussehen?   (Vorlage, Farben, Ecken)
         *   2. Was steht auf einer Kachel?            (Reihenfolge, Sichtbarkeit, Bildformat)
         *   3. Was passiert beim Klick darauf?        (Detailansicht, Adresse)
         *   4. Wie viel wird auf einmal geladen?      (Zeitraum pro Seite)
         *
         * Seit 1.19.0 ist diese Sortierung auch die Navigation: Jede der vier
         * Gruppen ist ein eigener Bereich des Design-Tabs
         * (`&section=style|tile|detail|list`, siehe designSections()), statt
         * dass alle vier untereinander auf einer Seite stehen.
         *
         * Seitenslugs waehlen nur aus, welcher do_settings_sections()-Aufruf
         * einen Abschnitt rendert; gespeichert wird weiterhin allein ueber
         * settings_fields(SettingsPage::PAGE_SLUG) in render().
         */
        $designStylePage = SettingsPage::PAGE_SLUG . '_design_style';
        add_settings_section('ctp_design_style', __('Stil', 'churchtools-plugin'), [self::class, 'renderDesignStyleIntro'], $designStylePage);
        // Ohne Beschriftungsspalte: Die vier Karten tragen ihren Namen selbst,
        // und eine Spalte mit dem Wort „Vorlage" daneben nimmt ihnen ein Achtel
        // der Breite, ohne etwas zu sagen (siehe .ctp-field--full in admin.css).
        // Die Reihenfolge-Editoren behalten ihre Beschriftung dagegen: In ihren
        // Panels stehen beschriftete Felder daneben, und eine Zeile ohne Label
        // wirkt dort abgerissen.
        add_settings_field('design_preset', __('Vorlage', 'churchtools-plugin'), [self::class, 'renderDesignPresetField'], $designStylePage, 'ctp_design_style', ['class' => 'ctp-field--full']);
        // Zweiter Abschnitt auf derselben Seite: Was die Vorlage vorgibt und
        // hier ueberschrieben wird, steht damit unmittelbar unter ihr statt
        // eine Bildschirmhoehe weiter unten.
        add_settings_section('ctp_design_look', __('Farben und Formen', 'churchtools-plugin'), [self::class, 'renderLookIntro'], $designStylePage);
        add_settings_field('corner_style', __('Ecken', 'churchtools-plugin'), [self::class, 'renderCornerStyleField'], $designStylePage, 'ctp_design_look');
        add_settings_field('accent_color', __('Akzentfarbe', 'churchtools-plugin'), [self::class, 'renderAccentColorField'], $designStylePage, 'ctp_design_look');
        add_settings_field('button_color', __('Buttonfarbe', 'churchtools-plugin'), [self::class, 'renderButtonColorField'], $designStylePage, 'ctp_design_look');
        /*
         * Steht hier und nicht unter „Aufbau der Kachel": Das Kennzeichen
         * erscheint in allen vier Ansichten *und* in der Detailansicht,
         * genau wie Ecken, Akzent- und Buttonfarbe daneben - der Abschnitt
         * „Kachel" ist laut seinem eigenen Kommentar denen vorbehalten, die
         * nur dort wirken.
         */
        add_settings_field('live_label', __('Laufende Termine', 'churchtools-plugin'), [self::class, 'renderLiveLabelField'], $designStylePage, 'ctp_design_look');

        $designTilePage = SettingsPage::PAGE_SLUG . '_design_tile';
        add_settings_section('ctp_design_order', __('Aufbau der Kachel', 'churchtools-plugin'), '__return_false', $designTilePage);
        // Voller Name statt nur „Reihenfolge": Derselbe Titel stand bis 1.19.0
        // zweimal auf derselben Seite - einmal hier fuer die Kachel, einmal
        // unten fuer die Detailansicht. Die Bereiche trennen sie inzwischen,
        // aber die Feldliste des Tabs liest man am Stueck (und Suchen im
        // Browser findet beide), also traegt jedes seinen Ort im Namen.
        add_settings_field('element_order', __('Reihenfolge auf der Kachel', 'churchtools-plugin'), [self::class, 'renderElementOrderField'], $designTilePage, 'ctp_design_order');
        // Beide betreffen ausschliesslich die Kachel: Die Sichtbarkeit arbeitet
        // auf CardDesign::TOGGLEABLE_KEYS, und das Seitenverhaeltnis greift nur
        // im Kachelbild (die Detailansicht begrenzt ihr Bild ueber die Hoehe).
        add_settings_field('hidden_elements', __('Ausgeblendete Felder', 'churchtools-plugin'), [self::class, 'renderFieldVisibilityField'], $designTilePage, 'ctp_design_order');
        add_settings_field('media_aspect_ratio', __('Bild-Seitenverhältnis', 'churchtools-plugin'), [self::class, 'renderMediaAspectRatioField'], $designTilePage, 'ctp_design_order');

        $designDetailPage = SettingsPage::PAGE_SLUG . '_design_detail';
        /*
         * Zwei Abschnitte auf einer Seite, und die Trennung ist nicht nur
         * Ordnung: Bei „Keine" gibt es gar keine Detailansicht, dann blendet
         * admin-design.js den Aufbau darunter aus. Solange beides in einem
         * Abschnitt stand, verschwanden mit ihm auch die Auswahlknoepfe, mit
         * denen man zurueckschaltet - auf der eigenen Bereichsseite waere die
         * Seite dadurch leer gewesen. Das Verhalten bleibt jetzt stehen.
         *
         * Das Klickverhalten steht vor der Reihenfolge, weil es die Frage davor
         * beantwortet: Gibt es ueberhaupt eine Detailansicht, und wo oeffnet
         * sie? Die Adresse folgt unmittelbar, sie ist die zweite Haelfte
         * derselben Entscheidung.
         */
        add_settings_section('ctp_design_detail_behavior', __('Verhalten', 'churchtools-plugin'), '__return_false', $designDetailPage);
        add_settings_field('click_behavior', __('Bei Klick auf eine Kachel', 'churchtools-plugin'), [self::class, 'renderClickBehaviorField'], $designDetailPage, 'ctp_design_detail_behavior');
        add_settings_field('detail_page_id', __('Adresse der Terminseite', 'churchtools-plugin'), [self::class, 'renderDetailPageField'], $designDetailPage, 'ctp_design_detail_behavior');

        add_settings_section('ctp_design_detail_order', __('Aufbau der Detailansicht', 'churchtools-plugin'), '__return_false', $designDetailPage);
        // Vor der Reihenfolge, aus demselben Grund wie das Klickverhalten
        // darüber: „Gibt es den Knopf überhaupt?" ist die Frage vor „wo steht
        // er?". In der Liste darunter lässt er sich danach frei verschieben.
        add_settings_field('detail_share_enabled', __('Teilen-Button', 'churchtools-plugin'), [self::class, 'renderDetailShareField'], $designDetailPage, 'ctp_design_detail_order');
        add_settings_field('detail_ics_enabled', __('Kalender-Button', 'churchtools-plugin'), [self::class, 'renderDetailIcsField'], $designDetailPage, 'ctp_design_detail_order');
        add_settings_field('detail_subscribe_enabled', __('Abo-Button', 'churchtools-plugin'), [self::class, 'renderDetailSubscribeField'], $designDetailPage, 'ctp_design_detail_order');
        add_settings_field('detail_element_order', __('Reihenfolge in der Detailansicht', 'churchtools-plugin'), [self::class, 'renderDetailElementOrderField'], $designDetailPage, 'ctp_design_detail_order');

        $designListPage = SettingsPage::PAGE_SLUG . '_design_list';
        add_settings_section('ctp_design_list', __('Listen', 'churchtools-plugin'), [self::class, 'renderListIntro'], $designListPage);
        add_settings_field('paging_months', __('Zeitraum pro Seite', 'churchtools-plugin'), [self::class, 'renderPagingMonthsField'], $designListPage, 'ctp_design_list');
    }

    /**
     * Der Reiter selbst: Bereichsnavigation, Formular, Vorschau.
     */
    public static function render(): void
    {
        /*
         * Ein Bereich je Seitenaufruf, nicht mehr alle vier
         * untereinander (siehe designSections()). Das Formular umfasst
         * weiterhin alles, was auf der Seite steht, mit dem
         * Layout-Raster *innerhalb*: Editor und die Vorschau, die er
         * treibt, stehen so in derselben Rasterzeile und sind waehrend
         * des Ziehens zusammen im Bild. Die Vorschauen enthalten keine
         * Formularfelder, sie mitzunehmen kostet also nichts. Siehe
         * .ctp-design-layout in admin.css.
         *
         * Der Stil bekommt die Kachel-Vorschau daneben (Nutzerwahl):
         * Vorlage, Ecken und die beiden Farben wirken sichtbar auf sie,
         * und blind eingestellte Farben waren der einzige Preis, den
         * die Aufteilung sonst gekostet haette. „Listen" hat als
         * einziger Bereich keine - die Seitenlaenge einer Liste zeigt
         * eine Kachel nicht.
         */
        $section = self::currentDesignSection();
        ?>
        <nav class="ctp-subtabs" aria-label="<?php esc_attr_e('Design-Bereiche', 'churchtools-plugin'); ?>">
            <?php foreach (self::designSections() as $sectionSlug => $sectionLabel) : ?>
                <a href="<?php echo esc_url(SettingsPage::tabUrl('design', ['section' => $sectionSlug])); ?>"
                    class="ctp-subtab <?php echo $section === $sectionSlug ? 'ctp-subtab--active' : ''; ?>"
                    <?php echo $section === $sectionSlug ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($sectionLabel); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form method="post" action="options.php" class="ctp-settings-form">
            <?php
            /*
             * settings_fields() legt neben nonce und option_page auch
             * _wp_http_referer ab - options.php schickt danach also auf
             * genau diese Adresse zurueck, samt `section`. Ohne das
             * landete jedes Speichern wieder im ersten Bereich.
             */
            settings_fields(SettingsPage::PAGE_SLUG);
            ?>
            <?php if ($section === 'list') : ?>
                <div class="ctp-panel">
                    <?php do_settings_sections(SettingsPage::PAGE_SLUG . '_design_list'); ?>
                </div>
            <?php else : ?>
                <div class="ctp-design-layout">
                    <div class="ctp-panel">
                        <?php do_settings_sections(SettingsPage::PAGE_SLUG . '_design_' . $section); ?>
                    </div>
                    <?php
                    if ($section === 'detail') {
                        self::renderDetailPreview();
                    } else {
                        self::renderDesignPreview();
                    }
                    ?>
                </div>
            <?php endif; ?>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>
        <?php
    }

    /**
     * Der Stil ist die Grundlage, auf der Kachel und Detailansicht aufsetzen —
     * deshalb der Bereich, auf dem der Design-Tab aufgeht.
     */
    private const DEFAULT_DESIGN_SECTION = 'style';

    /**
     * Die vier Bereiche des Design-Tabs, als eigene Ebene unter `tab=design`
     * (`&section=…`). Sie sind seit jeher vier getrennte Settings-Seiten
     * (siehe registerSettings()) — bis 1.19.0 standen sie nur alle vier
     * untereinander auf einer Seite: 13 Felder, rund 3.100 Zeichen
     * Beschreibung und bei 1440×900 gut fünf Bildschirme Höhe, das Fünffache
     * des nächstgrößten Tabs. Jeder Bereich bringt jetzt sein eigenes <form>
     * mit, was aus demselben Grund trägt wie bei den Tabs darüber:
     * sanitizeSettings() lässt Schlüssel, die nicht im $_POST stehen, stehen
     * (array_key_exists-Rückfall), und jede Checkbox trägt ihren versteckten
     * `0`-Zwilling, damit „abgehakt" von „nicht auf dieser Seite"
     * unterscheidbar bleibt.
     *
     * Die Schlüssel sind zugleich die Suffixe der Settings-Seiten
     * (`SettingsPage::PAGE_SLUG . '_design_' . $section`).
     */
    public static function designSections(): array
    {
        return [
            'style' => __('Stil', 'churchtools-plugin'),
            'tile' => __('Kachel', 'churchtools-plugin'),
            'detail' => __('Detailansicht', 'churchtools-plugin'),
            'list' => __('Listen', 'churchtools-plugin'),
        ];
    }

    public static function currentDesignSection(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation (which design section to display), not a state change; same pattern as currentTab().
        $section = sanitize_key((string) ($_GET['section'] ?? self::DEFAULT_DESIGN_SECTION));

        return array_key_exists($section, self::designSections()) ? $section : self::DEFAULT_DESIGN_SECTION;
    }

    /**
     * Global default for how much of the calendar one page of a list/grid shows
     * before the "Weitere Termine laden" button takes over. Individual
     * shortcodes/blocks/WPBakery elements can override it with months="…",
     * same relationship the click behavior already has.
     */
    public static function renderPagingMonthsField(): void
    {
        printf(
            '<input type="number" min="%1$s" max="%2$s" name="%3$s[paging_months]" value="%4$s" class="small-text" /> %5$s',
            esc_attr((string) EventWindow::MIN_MONTHS),
            esc_attr((string) EventWindow::MAX_MONTHS),
            esc_attr(SettingsPage::OPTION_KEY),
            esc_attr((string) Settings::get()['paging_months']),
            esc_html__('Monate', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Liste und Grid zeigen zunächst den angebrochenen aktuellen Monat plus so viele weitere Monate.', 'churchtools-plugin')
            . '</p>';
        echo '<ul class="description ctp-hint-list">'
            . '<li>' . esc_html__('Jeder Klick auf „Weitere Termine laden“ holt den nächsten Zeitraum; kürzere laden schneller.', 'churchtools-plugin') . '</li>'
            . '<li>' . esc_html__('Ein leerer Zeitraum springt zum nächsten Monat mit Terminen.', 'churchtools-plugin') . '</li>'
            . '<li>' . esc_html__('„Nächster Termin“ zeigt unabhängig davon eine feste Anzahl (Attribut „limit“).', 'churchtools-plugin') . '</li>'
            . '</ul>';
    }

    /**
     * German labels for CardDesign::ELEMENT_KEYS, shared by the drag&drop
     * field and (indirectly, via the same key set) the preview markup below.
     */
    private static function elementOrderLabels(): array
    {
        return [
            'media' => __('Bild (mit Datumsbadge)', 'churchtools-plugin'),
            'calendar' => __('Kalendername', 'churchtools-plugin'),
            'title' => __('Titel', 'churchtools-plugin'),
            'subtitle' => __('Untertitel', 'churchtools-plugin'),
            'excerpt' => __('Beschreibungsauszug', 'churchtools-plugin'),
            'date' => __('Datum', 'churchtools-plugin'),
            'time' => __('Uhrzeit', 'churchtools-plugin'),
            'location' => __('Ort', 'churchtools-plugin'),
        ];
    }

    /** German labels for CardDesign::SEPARATOR_TYPES, keyed the same way. */
    public static function separatorLabels(): array
    {
        return [
            'divider' => __('Trennlinie', 'churchtools-plugin'),
            'spacer' => __('Abstand', 'churchtools-plugin'),
        ];
    }

    public static function renderElementOrderField(): void
    {
        $labels = self::elementOrderLabels();
        $separatorLabels = self::separatorLabels();
        $order = Settings::get()['element_order'];
        ?>
        <ul
            id="ctp-design-order"
            class="ctp-order-list"
            data-default-order="<?php echo esc_attr(implode(',', CardDesign::DEFAULT_ORDER)); ?>"
        >
            <?php foreach ($order as $key) : ?>
                <?php $isSeparator = CardDesign::isSeparator($key); ?>
                <li
                    draggable="true"
                    data-key="<?php echo esc_attr($key); ?>"
                    class="ctp-order-item<?php echo $isSeparator ? ' ctp-order-item--separator' : ''; ?>"
                >
                    <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                    <?php if ($isSeparator) : ?>
                        <?php echo esc_html($separatorLabels[CardDesign::separatorType($key)] ?? $key); ?>
                        <button
                            type="button"
                            class="ctp-order-item__remove"
                            aria-label="<?php esc_attr_e('Entfernen', 'churchtools-plugin'); ?>"
                        >&times;</button>
                    <?php else : ?>
                        <?php echo esc_html($labels[$key] ?? $key); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <input
            type="hidden"
            id="ctp-design-order-input"
            name="<?php echo esc_attr(SettingsPage::OPTION_KEY); ?>[element_order]"
            value="<?php echo esc_attr(implode(',', $order)); ?>"
        />
        <p class="ctp-order-actions">
            <button type="button" class="button" id="ctp-design-add-divider">
                <?php esc_html_e('+ Trennlinie einfügen', 'churchtools-plugin'); ?>
            </button>
            <button type="button" class="button" id="ctp-design-add-spacer">
                <?php esc_html_e('+ Abstand einfügen', 'churchtools-plugin'); ?>
            </button>
            <?php
            // Getting back to the shipped layout after experimenting used to
            // mean dragging six rows into the right order by hand and deleting
            // every separator individually.
            ?>
            <button type="button" class="button-link ctp-order-reset" data-target="ctp-design-order">
                <?php esc_html_e('Standard wiederherstellen', 'churchtools-plugin'); ?>
            </button>
        </p>
        <?php
        /*
         * Vier Aussagen, die vorher als ein einziger Absatz unter dem Feld
         * standen und dort niemand zu Ende gelesen hat. Als Liste ist jede
         * Regel einzeln auffindbar - der Satz, den man gerade braucht, steht
         * in seiner eigenen Zeile.
         */
        ?>
        <p class="description"><?php esc_html_e('Reihenfolge per Drag&Drop ändern (Maus oder Trackpad, nicht per Touch):', 'churchtools-plugin'); ?></p>
        <ul class="description ctp-hint-list">
            <li><?php esc_html_e('Das Bild steht über oder unter dem Text, nie dazwischen.', 'churchtools-plugin'); ?></li>
            <li><?php esc_html_e('Trennlinien und Abstände lassen sich beliebig oft einfügen und per „×“ wieder entfernen.', 'churchtools-plugin'); ?></li>
            <li><?php esc_html_e('In der Liste und bei „Weitere Termine“ steht der Kalendername immer rechts neben dem Text, auf schmalen Bildschirmen darunter.', 'churchtools-plugin'); ?></li>
        </ul>
        <?php
    }

    /**
     * Section intro for the global block — the one place on this tab where a
     * setting's effect is not visible in a preview right next to it, so it says
     * where to look instead.
     */
    public static function renderDesignStyleIntro(): void
    {
        echo '<p class="description">'
            . esc_html__('Die Grundlage für alle Ansichten: Rundungen, Schatten, Ränder und das Verhalten unter der Maus.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * German labels and one-line descriptions for DesignPreset::PRESETS —
     * dieselbe Aufteilung wie bei elementOrderLabels(): Die Schlüssel gehören
     * der Frontend-Klasse, die Beschriftungen dieser Admin-Klasse.
     *
     * @return array<string, array{label: string, description: string}>
     */
    private static function designPresetLabels(): array
    {
        return [
            'standard' => [
                'label' => __('Standard', 'churchtools-plugin'),
                'description' => __('Weiche Rundungen, ruhiger Schatten, umrandetes Kalender-Etikett. Die bisherige Optik – wer nichts umstellt, bekommt genau das.', 'churchtools-plugin'),
            ],
            'ruhig' => [
                'label' => __('Ruhig', 'churchtools-plugin'),
                'description' => __('Zurückhaltend und architektonisch: feine Linien statt Schatten, fast keine Rundung, keine Bewegung beim Überfahren. Der Kalendername steht als farbiger Text da.', 'churchtools-plugin'),
            ],
            'warm' => [
                'label' => __('Warm', 'churchtools-plugin'),
                'description' => __('Großzügig und einladend: starke Rundungen, ein weicherer Schatten, mehr Luft zwischen den Kacheln, gefülltes Kalender-Etikett. Beim Überfahren hebt sich die Kachel und das Bild zoomt leicht.', 'churchtools-plugin'),
            ],
            'strukturiert' => [
                'label' => __('Strukturiert', 'churchtools-plugin'),
                'description' => __('Kontrastreich und redaktionell: rechtwinklig, ohne Schatten, dafür eine kräftige Kante in der Kalenderfarbe und deutliche Trennlinien.', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Vier Optionen als anklickbare Karten, jede mit einer eigenen kleinen
     * Vorschau daneben.
     *
     * Die Vorschau ist echtes Frontend-Markup unter der jeweiligen
     * Preset-Klasse, kein nachgebautes Abbild: frontend.css ist auf diesem Tab
     * ohnehin geladen (siehe enqueueAssets()), also zeigt jede Kachel genau
     * die Regeln, die später auch auf der Seite greifen. Ein nachgebautes
     * Abbild wäre die zweite Stelle, an der jedes Preset gepflegt werden
     * müsste – und die erste, die veraltet.
     */
    public static function renderDesignPresetField(): void
    {
        $current = DesignPreset::sanitize((string) Settings::get()['design_preset']);
        $labels = self::designPresetLabels();

        echo '<div class="ctp-preset-grid">';
        foreach (DesignPreset::PRESETS as $preset) {
            $texts = $labels[$preset] ?? ['label' => $preset, 'description' => ''];
            printf(
                '<label class="ctp-preset-option">'
                    . '<input type="radio" name="%1$s[design_preset]" value="%2$s" %3$s class="ctp-preset-input" />'
                    . '<span class="ctp-preset-body">'
                        . '<span class="ctp-preset-swatch ctp-events %4$s" aria-hidden="true">'
                            . '<span class="ctp-events__card">'
                                . '<span class="ctp-preset-swatch__media"></span>'
                                . '<span class="ctp-preset-swatch__text">'
                                    . '<span class="ctp-events__eyebrow">%5$s</span>'
                                    . '<span class="ctp-preset-swatch__line ctp-preset-swatch__line--title"></span>'
                                    . '<span class="ctp-preset-swatch__line"></span>'
                                . '</span>'
                            . '</span>'
                        . '</span>'
                        . '<span class="ctp-preset-name">%6$s</span>'
                        . '<span class="ctp-preset-description">%7$s</span>'
                    . '</span>'
                . '</label>',
                esc_attr(SettingsPage::OPTION_KEY),
                esc_attr($preset),
                checked($current, $preset, false),
                esc_attr(DesignPreset::bodyClass($preset)),
                esc_html__('Kalender', 'churchtools-plugin'),
                esc_html($texts['label']),
                esc_html($texts['description'])
            );
        }
        echo '</div>';
    }

    /**
     * Der Vorrang zwischen Vorlage und Einzeleinstellung ist die eine Regel,
     * die man hier kennen muss — sie steht deshalb dort, wo man sie braucht,
     * und nicht in der Beschreibung eines der drei Felder.
     */
    public static function renderLookIntro(): void
    {
        echo '<p class="description">'
            . esc_html__('Setzt sich gegen die Vorlage durch – „Eckig“ ergibt eckige Ecken auch in einer Vorlage mit runden.', 'churchtools-plugin')
            . '</p>';
    }

    public static function renderListIntro(): void
    {
        echo '<p class="description">'
            . esc_html__('Wie viel Liste und Grid auf einmal laden und was „Weitere Termine laden“ nachholt.', 'churchtools-plugin')
            . '</p>';
    }

    public static function renderCornerStyleField(): void
    {
        $current = Settings::get()['corner_style'];
        $options = [
            'rounded' => __('Rund', 'churchtools-plugin'),
            'square' => __('Eckig', 'churchtools-plugin'),
        ];

        foreach ($options as $value => $label) {
            printf(
                '<label class="ctp-radio-inline"><input type="radio" name="%1$s[corner_style]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(SettingsPage::OPTION_KEY),
                esc_attr($value),
                checked($current, $value, false),
                esc_html($label)
            );
        }
    }

    /**
     * Das Wort, das an einem gerade laufenden Termin steht.
     *
     * Ein freies Textfeld und keine Auswahlliste: „Läuft gerade", „Live",
     * „Jetzt", „Wir sind dabei" - welches davon passt, ist eine Frage des
     * Tonfalls der Gemeinde und nicht eine, auf die dieses Plugin drei
     * richtige Antworten kennt.
     *
     * Das leere Feld ist zugleich der Ausschalter, statt dass ein Kästchen
     * daneben stünde: Ein Kennzeichen ohne Wort gibt es nicht, ein zweites
     * Bedienelement für denselben Sachverhalt wäre eine Stelle mehr, an der
     * beide auseinanderlaufen können. Der Hinweistext sagt das ausdrücklich -
     * ohne ihn wäre es eine versteckte Nebenwirkung.
     */
    public static function renderLiveLabelField(): void
    {
        printf(
            '<input type="text" id="ctp-design-live-label" name="%1$s[live_label]" value="%2$s" class="regular-text" maxlength="%3$d" />',
            esc_attr(SettingsPage::OPTION_KEY),
            esc_attr((string) Settings::get()['live_label']),
            (int) LiveBadge::MAX_LABEL_LENGTH
        );
        echo '<p class="description">'
            . esc_html__('Steht neben dem Namen, solange ein Termin gerade stattfindet – in allen Ansichten. Leer lassen schaltet das Kennzeichen ab.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Hidden "[]" marker before the checkboxes, same reasoning as
     * renderKeepDataOnUninstallField()'s single hidden input: without it, an
     * all-unchecked submit posts no "hidden_elements" key at all, which
     * sanitizeSettings() would read as "this tab wasn't submitted" and keep
     * whatever was previously hidden instead of actually clearing it.
     */
    public static function renderFieldVisibilityField(): void
    {
        $labels = self::elementOrderLabels();
        $hidden = Settings::get()['hidden_elements'];
        printf('<input type="hidden" name="%1$s[hidden_elements][]" value="" />', esc_attr(SettingsPage::OPTION_KEY));
        foreach (CardDesign::TOGGLEABLE_KEYS as $key) {
            printf(
                '<label class="ctp-checkbox-block"><input type="checkbox" class="ctp-design-visibility-input" name="%1$s[hidden_elements][]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(SettingsPage::OPTION_KEY),
                esc_attr($key),
                checked(in_array($key, $hidden, true), true, false),
                esc_html($labels[$key] ?? $key)
            );
        }
        echo '<p class="description">'
            . esc_html__('Angehakte Felder erscheinen nicht auf der Kachel, der Titel bleibt immer. Für Popup und eigene Seite gilt „Aufbau der Detailansicht“.', 'churchtools-plugin')
            . '</p>';
    }

    public static function renderMediaAspectRatioField(): void
    {
        $current = Settings::get()['media_aspect_ratio'];
        $options = [
            'wide' => __('Breit – 16:9 (Standard)', 'churchtools-plugin'),
            'square' => __('Quadratisch – 1:1', 'churchtools-plugin'),
            'tall' => __('Hoch – 4:5', 'churchtools-plugin'),
        ];

        echo '<select id="ctp-design-media-ratio" name="' . esc_attr(SettingsPage::OPTION_KEY) . '[media_aspect_ratio]">';
        foreach ($options as $value => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">'
            . esc_html__('Für Grid-Kachel und „Nächster Termin“ – die Liste zeigt kein Bild.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * "Zusätzlich zur Kalenderfarbe" (see plan.md): a per-event calendar
     * color, when set, is written as an inline --ctp-accent on a more
     * specific element than this setting's own inline style (see
     * CardDesign class docblock/frontend.css), so it keeps winning the CSS
     * cascade automatically — no extra precedence logic needed here, this
     * setting only ever supplies the fallback for events whose calendar has
     * no color of its own.
     */
    public static function renderAccentColorField(): void
    {
        $settings = Settings::get();
        printf('<input type="hidden" name="%1$s[accent_color_enabled]" value="0" />', esc_attr(SettingsPage::OPTION_KEY));
        printf(
            '<label><input type="checkbox" id="ctp-design-accent-enabled" name="%1$s[accent_color_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(SettingsPage::OPTION_KEY),
            checked(!empty($settings['accent_color_enabled']), true, false),
            esc_html__('Eigene Akzentfarbe verwenden', 'churchtools-plugin')
        );
        // Same swatch + hex-field pair as the calendar rows (see
        // CalendarsTab::renderCalendarCard()), wrapped in the .ctp-color-field the shared
        // inline script keys its two-way sync off.
        printf(
            '<p class="ctp-color-field">'
            . '<input type="color" id="ctp-design-accent-color" class="ctp-color-input" name="%1$s[accent_color]" value="%2$s" aria-label="%3$s" %5$s />'
            . '<input type="text" class="ctp-color-hex" value="%2$s" maxlength="7" spellcheck="false" autocomplete="off" aria-label="%4$s" %5$s />'
            . '<button type="button" class="button-link ctp-color-reset" data-default-color="%6$s" %5$s>%7$s</button>'
            . '</p>',
            esc_attr(SettingsPage::OPTION_KEY),
            esc_attr($settings['accent_color']),
            esc_attr__('Akzentfarbe wählen', 'churchtools-plugin'),
            esc_attr__('Akzentfarbe als Hex-Code', 'churchtools-plugin'),
            disabled(empty($settings['accent_color_enabled']), true, false),
            esc_attr(Settings::defaults()['accent_color']),
            esc_html__('Zurücksetzen', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Für Icons, Datumsbadges, Ränder und aktive Eventfinder-Buttons. Termine mit eigener Kalenderfarbe behalten diese.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Sibling of renderAccentColorField() above, for the one thing the accent
     * deliberately no longer controls: the interactive chrome. The two are
     * separate settings because --ctp-accent doubles as a *calendar's*
     * identity color (each card re-sets it inline), so it could never be a
     * reliable button color — see frontend.css's --ctp-color-button-* block.
     */
    public static function renderButtonColorField(): void
    {
        $settings = Settings::get();
        printf('<input type="hidden" name="%1$s[button_color_enabled]" value="0" />', esc_attr(SettingsPage::OPTION_KEY));
        printf(
            '<label><input type="checkbox" id="ctp-design-button-enabled" name="%1$s[button_color_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(SettingsPage::OPTION_KEY),
            checked(!empty($settings['button_color_enabled']), true, false),
            esc_html__('Eigene Buttonfarbe verwenden', 'churchtools-plugin')
        );
        printf(
            '<p class="ctp-color-field">'
            . '<input type="color" id="ctp-design-button-color" class="ctp-color-input" name="%1$s[button_color]" value="%2$s" aria-label="%3$s" %5$s />'
            . '<input type="text" class="ctp-color-hex" value="%2$s" maxlength="7" spellcheck="false" autocomplete="off" aria-label="%4$s" %5$s />'
            . '<button type="button" class="button-link ctp-color-reset" data-default-color="%6$s" %5$s>%7$s</button>'
            . '</p>',
            esc_attr(SettingsPage::OPTION_KEY),
            esc_attr($settings['button_color']),
            esc_attr__('Buttonfarbe wählen', 'churchtools-plugin'),
            esc_attr__('Buttonfarbe als Hex-Code', 'churchtools-plugin'),
            disabled(empty($settings['button_color_enabled']), true, false),
            esc_attr(Settings::defaults()['button_color']),
            esc_html__('Zurücksetzen', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Füllung von Eventfinder-, Nachlade- und Schließen-Button, wenn ausgewählt oder unter der Maus. Die Schrift darauf wird automatisch schwarz oder weiß.', 'churchtools-plugin')
            . '</p>';
    }

    public static function renderClickBehaviorField(): void
    {
        $current = Settings::get()['click_behavior'];
        $options = [
            'none' => __('Keine – Kacheln bleiben wie bisher unklickbar', 'churchtools-plugin'),
            'popup' => __('Popup – öffnet die Details in einem Fenster auf derselben Seite', 'churchtools-plugin'),
            'page' => __('Eigene Seite – verlinkt auf eine eigene Termin-URL', 'churchtools-plugin'),
        ];

        foreach ($options as $value => $label) {
            printf(
                '<label class="ctp-radio-block"><input type="radio" name="%1$s[click_behavior]" value="%2$s" class="ctp-design-click-input" %3$s /> %4$s</label>',
                esc_attr(SettingsPage::OPTION_KEY),
                esc_attr($value),
                checked($current, $value, false),
                esc_html($label)
            );
        }
        echo '<p class="description">'
            . esc_html__('Gilt überall, wo Termine eingebunden sind – außer ein Eintrag setzt click selbst (siehe „Events → Einbinden“).', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Wählt die Seite, unter deren Adresse die Termine liegen. Zwei Dinge
     * hängen daran, und das zweite ist das wichtigere:
     *
     *   - die Adresse: /termine/gottesdienst-06-09-2026/ statt
     *     /churchtools-termin/4021/
     *   - die Einbettung ins Theme: Mit Elternseite liefert WordPress eine
     *     ganz normale Seite aus, wir tauschen nur ihren Inhalt. Ohne
     *     Elternseite gibt es keinen echten Beitrag, und auf einem Block-Theme
     *     bekommt die Termin-Adresse dann weder dessen Vorlage noch dessen
     *     Kopf- und Fußbereich (siehe Frontend\EventDetailPage).
     */
    public static function renderDetailPageField(): void
    {
        $settings = Settings::get();
        $current = (int) $settings['detail_page_id'];

        /*
         * phpcs kennt wp_dropdown_pages() als ausgebende Funktion und verlangt
         * deshalb escapte Argumente. Die besorgt hier WordPress selbst: name,
         * id, class und option_none_value laufen dort durch esc_attr(), die
         * Seitentitel durch esc_html(). Ausgerechnet show_option_none wird
         * *nicht* escapt (wp-includes/post-template.php, Zeile mit
         * `$parsed_args['show_option_none']` im Options-Markup) - deshalb steht
         * darin unten esc_html__() statt __(), und nur deshalb ist die
         * Abschaltung hier vollständig.
         */
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        wp_dropdown_pages([
            'name' => SettingsPage::OPTION_KEY . '[detail_page_id]',
            'selected' => $current,
            'show_option_none' => esc_html__('— Keine — eigene Adresse ohne Elternseite', 'churchtools-plugin'),
            'option_none_value' => '0',
            'post_status' => 'publish',
            // Gar nicht erst zur Auswahl stellen, was der Sanitizer daneben
            // ohnehin ablehnen muesste — siehe dort.
            'exclude' => implode(',', array_filter([
                (int) get_option('page_on_front'),
                (int) get_option('page_for_posts'),
            ])),
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        if ($current > 0 && get_post_status($current) === 'publish') {
            printf(
                '<p class="description">%s <code>%s</code></p>',
                esc_html__('Termine liegen dann unter:', 'churchtools-plugin'),
                esc_html(trailingslashit((string) get_permalink($current)) . 'gottesdienst-06-09-2026/')
            );
        }

        echo '<p class="description">'
            . esc_html__('Die Seite selbst bleibt, wie sie ist; nur mit angehängtem Termin zeigt sie diesen. Alte Termin-Adressen leiten weiter.', 'churchtools-plugin')
            . '</p>';
        echo '<p class="description">'
            . esc_html__('Empfohlen – ohne sie steht die Terminseite außerhalb der Vorlage des Themes. Startseite und Beitragsseite stehen nicht zur Wahl.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Das Häkchen, an dem der „Teilen"-Knopf hängt. Seine Stelle in der
     * Detailansicht bestimmt dagegen die Reihenfolge-Liste darunter — der
     * Schlüssel „share" steht dort immer, ob das Häkchen gesetzt ist oder
     * nicht (Begründung siehe DetailDesign).
     */
    public static function renderDetailShareField(): void
    {
        printf('<input type="hidden" name="%1$s[detail_share_enabled]" value="0" />', esc_attr(SettingsPage::OPTION_KEY));
        printf(
            '<label><input type="checkbox" id="ctp-design-detail-share" name="%1$s[detail_share_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(SettingsPage::OPTION_KEY),
            checked(!empty(Settings::get()['detail_share_enabled']), true, false),
            esc_html__('„Teilen“-Button in Popup und eigener Terminseite anzeigen', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Öffnet auf dem Telefon das Teilen-Menü, am Rechner kopiert er die Adresse. Ohne Drittanbieter-Skript und ohne Zählpixel.', 'churchtools-plugin')
            . '</p>';
        echo '<p class="description">'
            . esc_html__('Nur in der Detailansicht, nicht auf den Kacheln – wo genau, legt die Reihenfolge darunter fest.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Das Häkchen für „Importieren". Getrennt vom Teilen-Knopf,
     * weil es eine andere Frage beantwortet: Teilen richtet sich an andere,
     * der Kalendereintrag an einen selbst.
     */
    public static function renderDetailIcsField(): void
    {
        printf('<input type="hidden" name="%1$s[detail_ics_enabled]" value="0" />', esc_attr(SettingsPage::OPTION_KEY));
        printf(
            '<label><input type="checkbox" id="ctp-design-detail-ics" name="%1$s[detail_ics_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(SettingsPage::OPTION_KEY),
            checked(!empty(Settings::get()['detail_ics_enabled']), true, false),
            esc_html__('„Importieren"-Button in Popup und eigener Terminseite anzeigen', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Lädt den Termin als Kalenderdatei herunter, die Handy, Outlook und Thunderbird direkt öffnen.', 'churchtools-plugin')
            . '</p>';
        echo '<p class="description">'
            . esc_html__('Eine Momentaufnahme: Spätere Änderungen kommen erst mit erneutem Herunterladen im Kalender an.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Der dritte Knopf derselben Reihe, und der einzige, der nicht diesen
     * einen Termin meint: Ein Abonnement spiegelt alle künftigen.
     *
     * Er ersetzt „Importieren" nicht, er beantwortet die andere Frage — wer
     * das Konzert im Kalender haben will, braucht kein Abonnement, wer
     * regelmäßig kommt, keinen Download. Nachgemessen passen allerdings zwei
     * Chips nebeneinander und nicht drei (siehe partials/
     * event-detail-element.php), deshalb steht hier der Hinweis statt einer
     * stillen Enge im Popup.
     */
    public static function renderDetailSubscribeField(): void
    {
        printf('<input type="hidden" name="%1$s[detail_subscribe_enabled]" value="0" />', esc_attr(SettingsPage::OPTION_KEY));
        printf(
            '<label><input type="checkbox" id="ctp-design-detail-subscribe" name="%1$s[detail_subscribe_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(SettingsPage::OPTION_KEY),
            checked(!empty(Settings::get()['detail_subscribe_enabled']), true, false),
            esc_html__('„Abonnieren"-Button in Popup und eigener Terminseite anzeigen', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Trägt alle künftigen Termine dauerhaft in den Kalender des Besuchers ein – Verschiebungen und Absagen kommen von selbst an.', 'churchtools-plugin')
            . '</p>';
        echo '<p class="description">'
            . esc_html__('Zwei Buttons passen nebeneinander, drei werden im Popup eng. Wer diesen einschaltet, schaltet „Importieren“ am besten ab.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * German labels for DetailDesign::ELEMENT_KEYS. Same shape as
     * elementOrderLabels() above, but describing the detail view's own field
     * set (full description instead of an excerpt, no separate calendar/media
     * split needed beyond what the card already establishes).
     */
    private static function detailElementOrderLabels(): array
    {
        return [
            'media' => __('Bild', 'churchtools-plugin'),
            'calendar' => __('Kalendername', 'churchtools-plugin'),
            'title' => __('Titel', 'churchtools-plugin'),
            'subtitle' => __('Untertitel', 'churchtools-plugin'),
            'date' => __('Datum', 'churchtools-plugin'),
            'time' => __('Uhrzeit', 'churchtools-plugin'),
            'location' => __('Ort', 'churchtools-plugin'),
            'description' => __('Beschreibung', 'churchtools-plugin'),
            'share' => __('Teilen-Button', 'churchtools-plugin'),
            'ics' => __('Kalender-Button', 'churchtools-plugin'),
            'subscribe' => __('Abo-Button', 'churchtools-plugin'),
        ];
    }

    public static function renderDetailElementOrderField(): void
    {
        $labels = self::detailElementOrderLabels();
        $order = Settings::get()['detail_element_order'];
        ?>
        <ul
            id="ctp-design-detail-order"
            class="ctp-order-list"
            data-default-order="<?php echo esc_attr(implode(',', DetailDesign::DEFAULT_ORDER)); ?>"
        >
            <?php foreach ($order as $key) : ?>
                <li draggable="true" data-key="<?php echo esc_attr($key); ?>" class="ctp-order-item">
                    <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                    <?php echo esc_html($labels[$key] ?? $key); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <input
            type="hidden"
            id="ctp-design-detail-order-input"
            name="<?php echo esc_attr(SettingsPage::OPTION_KEY); ?>[detail_element_order]"
            value="<?php echo esc_attr(implode(',', $order)); ?>"
        />
        <p class="ctp-order-actions">
            <button type="button" class="button-link ctp-order-reset" data-target="ctp-design-detail-order">
                <?php esc_html_e('Standard wiederherstellen', 'churchtools-plugin'); ?>
            </button>
        </p>
        <p class="description">
            <?php esc_html_e('Reihenfolge der Felder in Popup und eigener Seite, per Drag&Drop änderbar (Maus oder Trackpad, nicht per Touch).', 'churchtools-plugin'); ?>
        </p>
        <?php
    }

    /**
     * Reuses the real grid-card markup/classes with placeholder content, so
     * the preview reflects the actual rendering rules instead of a
     * hand-drawn approximation. Accent/surface colors fall back to this
     * stylesheet's plain defaults (not the active theme's Global Styles)
     * because --wp--preset--color--* custom properties are only emitted on
     * the frontend, not in wp-admin — expected, not a bug, since color isn't
     * part of what this feature controls.
     */
    private static function renderDesignPreview(): void
    {
        $settings = Settings::get();
        $style = CardDesign::styleAttribute(
            $settings['element_order'],
            $settings['corner_style'],
            $settings['media_aspect_ratio'],
            $settings['accent_color_enabled'] ? $settings['accent_color'] : '',
            $settings['button_color_enabled'] ? $settings['button_color'] : ''
        );
        $hidden = $settings['hidden_elements'];
        // Das Kennzeichen steht in der Vorschau *immer* sichtbar, sobald ein
        // Wort eingestellt ist - anders als im Frontend, wo ein Skript es an
        // die Uhr bindet. Die Vorschau zeigt, wie es aussieht, nicht wann es
        // erscheint; ein Beispiel-Termin am 24.12. laeuft ohnehin nie gerade.
        $liveLabel = LiveBadge::sanitizeLabel((string) $settings['live_label']);
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Vorschau', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Vorschau als Grid-Kachel – die Einstellung gilt gleichermaßen für Grid, Liste und „Nächster Termin“.', 'churchtools-plugin'); ?>
            </p>
            <div class="ctp-events ctp-events--grid ctp-design-preview-frame <?php echo esc_attr(DesignPreset::bodyClass((string) $settings['design_preset'])); ?>" id="ctp-design-preview" style="<?php echo esc_attr($style); ?>">
                <ul class="ctp-events__list">
                    <li>
                        <article class="ctp-events__card">
                            <div class="ctp-events__media" data-key="media" <?php echo in_array('media', $hidden, true) ? 'hidden' : ''; ?>>
                                <span class="ctp-events__date-badge" aria-hidden="true">
                                    <span class="ctp-events__day">24</span>
                                    <span class="ctp-events__month"><?php esc_html_e('Dez', 'churchtools-plugin'); ?></span>
                                </span>
                            </div>
                            <div class="ctp-events__content" id="ctp-design-preview-content">
                                <span class="ctp-events__eyebrow" data-key="calendar" <?php echo in_array('calendar', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php esc_html_e('Beispiel-Kalender', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__title">
                                    <?php esc_html_e('Beispiel-Termin', 'churchtools-plugin'); ?>
                                    <span class="ctp-events__badge ctp-events__badge--live" id="ctp-design-live-preview" <?php echo $liveLabel === '' ? 'hidden' : ''; ?>>
                                        <span class="ctp-events__live-dot" aria-hidden="true"></span>
                                        <span id="ctp-design-live-preview-text"><?php echo esc_html($liveLabel); ?></span>
                                    </span>
                                </span>
                                <span class="ctp-events__subtitle" data-key="subtitle" <?php echo in_array('subtitle', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php esc_html_e('Untertitel-Beispiel', 'churchtools-plugin'); ?>
                                </span>
                                <span class="ctp-events__meta-item ctp-events__meta-item--date" data-key="date" <?php echo in_array('date', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons:: returns fixed, hard-coded SVG markup with no request input (see Icons.php docblock). ?>
                                    <?php echo Icons::calendar(); ?>
                                    24.12.2026
                                </span>
                                <span class="ctp-events__meta-item ctp-events__meta-item--time" data-key="time" <?php echo in_array('time', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::clock(); ?>
                                    18:00–20:00
                                </span>
                                <span class="ctp-events__meta-item ctp-events__meta-item--location" data-key="location" <?php echo in_array('location', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
                                    <?php echo Icons::location(); ?>
                                    <?php esc_html_e('Gemeindehaus', 'churchtools-plugin'); ?>
                                </span>
                                <p class="ctp-events__excerpt" data-key="excerpt" <?php echo in_array('excerpt', $hidden, true) ? 'hidden' : ''; ?>>
                                    <?php esc_html_e('Kurzer Auszug aus der Terminbeschreibung, wie er auf der Kachel erscheint …', 'churchtools-plugin'); ?>
                                </p>
                                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CardDesign::renderSeparators() builds its own escaped markup. ?>
                                <?php echo CardDesign::renderSeparators($settings['element_order']); ?>
                            </div>
                        </article>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Preview for the detail view (popup/own page), rendered in the
     * detail_element_order's actual server-side order — unlike the card
     * preview above, no CSS `order` custom properties are involved (see
     * DetailDesign docblock), so admin-design.js's drag handler just
     * re-appends these same placeholder blocks in the new order instead of
     * mirroring CSS var math.
     */
    private static function renderDetailPreview(): void
    {
        $settings = Settings::get();
        $order = DetailDesign::isValidOrder($settings['detail_element_order'])
            ? $settings['detail_element_order']
            : DetailDesign::DEFAULT_ORDER;
        // Only the corner style is legible in this preview (element order is
        // server-rendered here, see the docblock), but it's the same style
        // attribute the card preview gets — cheaper than a second code path,
        // and admin-design.js keeps both frames in sync on change.
        $style = CardDesign::styleAttribute(
            $settings['element_order'],
            $settings['corner_style'],
            $settings['media_aspect_ratio'],
            $settings['accent_color_enabled'] ? $settings['accent_color'] : '',
            $settings['button_color_enabled'] ? $settings['button_color'] : ''
        );

        $blocks = [
            'media' => '<div class="ctp-events__detail-media" aria-hidden="true">'
                . '<div class="ctp-events__detail-media-frame ctp-design-preview-block__media"></div></div>',
            'calendar' => '<span class="ctp-events__eyebrow">'
                . esc_html__('Beispiel-Kalender', 'churchtools-plugin') . '</span>',
            'title' => '<h2 class="ctp-events__detail-title">' . esc_html__('Beispiel-Termin', 'churchtools-plugin') . '</h2>',
            'subtitle' => '<p class="ctp-events__subtitle">' . esc_html__('Untertitel-Beispiel', 'churchtools-plugin') . '</p>',
            'date' => '<p class="ctp-events__meta-item ctp-events__meta-item--date">'
                . Icons::calendar() . ' 24.12.2026</p>',
            'time' => '<p class="ctp-events__meta-item ctp-events__meta-item--time">'
                . Icons::clock() . ' 18:00–20:00</p>',
            'location' => '<p class="ctp-events__meta-item ctp-events__meta-item--location">'
                . Icons::location() . ' ' . esc_html__('Gemeindehaus', 'churchtools-plugin') . '</p>',
            'description' => '<div class="ctp-events__detail-description"><p>'
                . esc_html__('Vollständige Terminbeschreibung, wie sie in Popup und eigener Seite erscheint …', 'churchtools-plugin') . '</p></div>',
            // Dieselben Klassen wie im Frontend (siehe
            // partials/event-detail-element.php), damit die Vorschau denselben
            // Regeln folgt statt einer nachgebauten Annäherung — ohne die
            // data-Attribute, denn hier wird nichts geteilt.
            'share' => '<div class="ctp-events__share"><button type="button" class="ctp-events__share-btn ctp-button ctp-button--quiet">'
                . Icons::share() . esc_html__('Teilen', 'churchtools-plugin') . '</button></div>',
            'ics' => '<div class="ctp-events__share"><span class="ctp-events__share-btn ctp-button ctp-button--quiet">'
                . Icons::calendarPlus() . esc_html__('Importieren', 'churchtools-plugin') . '</span></div>',
            'subscribe' => '<div class="ctp-events__share"><span class="ctp-events__share-btn ctp-button ctp-button--quiet">'
                . Icons::calendarPlus() . esc_html__('Abonnieren', 'churchtools-plugin') . '</span></div>',
        ];
        // Der einzige Schlüssel, dessen Sichtbarkeit nicht an seiner Position
        // hängt. admin-design.js hält das Attribut danach am Häkchen aktuell.
        $shareEnabled = !empty($settings['detail_share_enabled']);
        $icsEnabled = !empty($settings['detail_ics_enabled']);
        $subscribeEnabled = !empty($settings['detail_subscribe_enabled']);
        /*
         * Die Rahmung des Termins - und der einzige sichtbare Unterschied
         * zwischen den beiden Klickverhalten: Im Popup steht oben rechts das
         * Schliessen-Kreuz (partials/modal.php), auf der eigenen Seite oben
         * links der Zurueck-Knopf (templates/event-detail.php). Beides steht
         * hier ausserhalb der [data-key]-Bloecke, weil es nicht zur
         * einstellbaren Reihenfolge gehoert, sondern zum Fenster darum.
         *
         * Als <span> und nicht als <button>/<a>: Die Vorschau steht mitten im
         * Einstellungsformular, und ein Bedienelement, das nichts bedient,
         * faengt dort nur Fokus und Klicks ab. Dieselbe Entscheidung wie beim
         * Importieren-Knopf oben. admin-design.js schaltet die beiden mit den
         * Klickverhalten-Knoepfen um.
         */
        $clickBehavior = (string) $settings['click_behavior'];
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Vorschau Detailansicht', 'churchtools-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Gilt für Popup und eigene Seite gleichermaßen – nur der Rahmen darum unterscheidet sie.', 'churchtools-plugin'); ?>
            </p>
            <div class="ctp-design-preview-backdrop">
                <div
                    class="ctp-events ctp-events__detail ctp-design-preview-frame <?php echo esc_attr(DesignPreset::bodyClass((string) $settings['design_preset'])); ?>"
                    id="ctp-design-detail-preview"
                    style="<?php echo esc_attr($style); ?>"
                >
                    <span
                        class="ctp-events__modal-close ctp-design-preview-chrome"
                        id="ctp-design-preview-close"
                        aria-hidden="true"
                        <?php echo $clickBehavior === 'popup' ? '' : 'hidden'; ?>
                    >&times;</span>
                    <span
                        class="ctp-events__back ctp-button ctp-design-preview-chrome"
                        id="ctp-design-preview-back"
                        aria-hidden="true"
                        <?php echo $clickBehavior === 'page' ? '' : 'hidden'; ?>
                    >&larr; <?php esc_html_e('Zurück', 'churchtools-plugin'); ?></span>
                    <?php foreach ($order as $key) : ?>
                        <div data-key="<?php echo esc_attr($key); ?>" <?php echo self::previewBlockHidden($key, $shareEnabled, $icsEnabled, $subscribeEnabled) ? 'hidden' : ''; ?>>
                            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $blocks entries are built above from esc_html()/esc_html__()-wrapped strings plus Icons::, same trust boundary as the rest of this admin-only preview markup. ?>
                            <?php echo $blocks[$key] ?? ''; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Die zwei Schluessel, deren Sichtbarkeit in der Vorschau nicht an ihrer
     * Position haengt, sondern an einem eigenen Haekchen. admin-design.js haelt
     * das Attribut danach aktuell; hier geht es nur um den Zustand beim Laden.
     */
    private static function previewBlockHidden(
        string $key,
        bool $shareEnabled,
        bool $icsEnabled,
        bool $subscribeEnabled
    ): bool {
        return ($key === DetailDesign::SHARE_KEY && !$shareEnabled)
            || ($key === DetailDesign::ICS_KEY && !$icsEnabled)
            || ($key === DetailDesign::SUBSCRIBE_KEY && !$subscribeEnabled);
    }
}
