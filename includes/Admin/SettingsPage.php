<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Frontend\CardDesign;
use ChurchToolsPlugin\Frontend\DesignPreset;
use ChurchToolsPlugin\Frontend\DetailDesign;
use ChurchToolsPlugin\Frontend\EventWindow;
use ChurchToolsPlugin\Frontend\LiveBadge;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Posts\PostSettings;
use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Settings;
use ChurchToolsPlugin\Sync\CalendarList;
use ChurchToolsPlugin\Sync\ResourceList;
use ChurchToolsPlugin\Sync\RunLock;
use ChurchToolsPlugin\Sync\SyncEngine;
use ChurchToolsPlugin\Update\GitHubUpdateChecker;
use Throwable;

final class SettingsPage
{
    public const OPTION_KEY = Settings::OPTION_KEY;

    public const PAGE_SLUG = 'churchtools-plugin';

    /** @var string[] Die Seiten-Hooks aller vier Bereiche, fuer enqueueAssets(). */
    private array $pageHooks = [];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'redirectLegacyTabUrl']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_ctp_test_connection', [$this, 'ajaxTestConnection']);
        add_action('wp_ajax_ctp_fetch_calendars', [$this, 'ajaxFetchCalendars']);
        add_action('wp_ajax_ctp_fetch_resources', [$this, 'ajaxFetchResources']);
        add_action('wp_ajax_ctp_run_sync', [$this, 'ajaxRunSync']);
        add_action('wp_ajax_ctp_check_updates', [$this, 'ajaxCheckUpdates']);
    }

    public function addMenuPage(): void
    {
        $this->pageHooks[] = (string) add_menu_page(
            __('ChurchTools Events', 'churchtools-plugin'),
            __('ChurchTools', 'churchtools-plugin'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage'],
            'dashicons-calendar-alt',
            26
        );

        foreach (self::areas() as $area => $label) {
            $this->pageHooks[] = (string) add_submenu_page(
                self::PAGE_SLUG,
                $label,
                $label,
                'manage_options',
                self::areaSlug($area),
                [$this, 'renderPage']
            );
        }
    }

    /**
     * Die Bereiche des Backends, zugleich die Eintraege im linken
     * WordPress-Menue (Nutzerentscheidung 2026-09-14: „links im Menue eine
     * Uebersicht und Einstiegspunkte fuer Events bzw. Gruppen", Design unter
     * „Einstellungen"). Die Beitraege kamen 2026-10-07 als eigener Bereich
     * dazu, nach derselben Regel: eigenes Thema, eigener Eintrag.
     *
     * Bis dahin gab es eine Reiterreihe mit zehn Knoepfen und links drei
     * Abkuerzungen, die per `&tab=` in dieselbe Seite fuehrten. Mit den Gruppen
     * standen dort zwei verschiedene Themen durcheinander. Jetzt ist jeder
     * Bereich eine eigene Unterseite mit eigenem Slug, auf der nur noch seine
     * Reiter stehen - WordPress markiert den Menueeintrag damit von selbst.
     *
     * @return array<string, string>
     */
    private static function areas(): array
    {
        return [
            'overview' => __('Übersicht', 'churchtools-plugin'),
            'events' => __('Events', 'churchtools-plugin'),
            'groups' => __('Gruppen', 'churchtools-plugin'),
            'posts' => __('Beiträge', 'churchtools-plugin'),
            'settings' => __('Einstellungen', 'churchtools-plugin'),
        ];
    }

    /**
     * Welche Reiter zu welchem Bereich gehoeren, in der Reihenfolge ihrer
     * Knoepfe. Vorne steht, was der Bereich *zeigt*, dahinter, womit man ihn
     * einrichtet (Nutzerwunsch 2026-09-14: „die primaeren Infos in die erste
     * Position") - der erste Reiter ist zugleich das, was ein Klick auf den
     * Menueeintrag oeffnet. Die Reiter-Schluessel sind dieselben wie vorher - sie benennen
     * zugleich die Settings-Seiten (siehe registerSettings()), und
     * `&tab=calendars` bleibt als Adresse gueltig.
     *
     * Termine und Gruppen folgen derselben Reihe: Liste, Auswahl,
     * Synchronisation, Einbinden (Nutzerwunsch 2026-09-15). Die Raeume gibt
     * es nur bei den Terminen. Bis dahin stand die Synchronisation der Gruppen
     * unten im Reiter „Homepages" und wurde dort nicht gefunden.
     */
    private const AREA_TABS = [
        'overview' => ['status'],
        'events' => ['events', 'calendars', 'rooms', 'sync', 'embed'],
        'groups' => ['group_list', 'groups', 'group_sync', 'group_embed'],
        // Ohne Auswahl-Reiter: Was oeffentlich ist, entscheidet ChurchTools,
        // der Schalter steht unter „Synchronisation" (siehe PostsTab).
        'posts' => ['post_list', 'post_sync', 'post_embed'],
        'settings' => ['connection', 'design', 'updates', 'log'],
    ];

    /**
     * Der Seiten-Slug eines Bereichs. Die Uebersicht behaelt den Slug des
     * Hauptmenuepunkts: Sobald ein Menuepunkt Untereintraege hat, verlinkt er
     * auf den ersten davon (wp-admin/menu-header.php), und ein Klick auf
     * „ChurchTools" soll weiter dorthin fuehren, wo er immer hinfuehrte.
     */
    /**
     * Symbol und Unterzeile im Kopf jeder Bereichsseite. Die Ueberschrift ist
     * der Name des Menueeintrags (Nutzerwunsch 2026-09-14: „matche sie auf das
     * entsprechende Menue") - vorher stand auf jeder Seite „ChurchTools
     * Events" mit einer Unterzeile ueber Kalender, auch unter „Gruppen".
     *
     * @return array{icon: string, tagline: string}
     */
    private static function areaHeader(string $area): array
    {
        $headers = [
            'overview' => [
                'icon' => 'dashboard',
                'tagline' => __('Der Zustand von Events, Gruppen und Beiträgen auf einen Blick.', 'churchtools-plugin'),
            ],
            'events' => [
                'icon' => 'calendar-alt',
                'tagline' => __('Kalender-Events aus ChurchTools synchronisieren und anzeigen.', 'churchtools-plugin'),
            ],
            'groups' => [
                'icon' => 'groups',
                'tagline' => __('Gruppen aus den Gruppen-Homepages in ChurchTools übernehmen und anzeigen.', 'churchtools-plugin'),
            ],
            'posts' => [
                'icon' => 'megaphone',
                'tagline' => __('Beiträge öffentlicher Gruppen aus ChurchTools übernehmen und anzeigen.', 'churchtools-plugin'),
            ],
            'settings' => [
                'icon' => 'admin-settings',
                'tagline' => __('Verbindung, Design und Updates – gilt für Events, Gruppen und Beiträge.', 'churchtools-plugin'),
            ],
        ];

        return $headers[$area] ?? $headers['overview'];
    }

    public static function areaSlug(string $area): string
    {
        return $area === 'overview' ? self::PAGE_SLUG : self::PAGE_SLUG . '-' . $area;
    }

    private static function areaOfTab(string $tab): string
    {
        foreach (self::AREA_TABS as $area => $tabs) {
            if (in_array($tab, $tabs, true)) {
                return $area;
            }
        }

        return 'overview';
    }

    private static function currentArea(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation (which area to display), not a state change.
        $page = sanitize_key((string) ($_GET['page'] ?? ''));

        foreach (array_keys(self::areas()) as $area) {
            if (self::areaSlug($area) === $page) {
                return $area;
            }
        }

        return 'overview';
    }

    /**
     * Die Adresse eines Reiters, samt der Unterseite seines Bereichs. Alle
     * Verweise im Backend gehen hierueber, damit keiner mehr eine Seite und
     * einen Reiter zusammenbaut, die nicht zueinander gehoeren.
     *
     * @param array<string, string|int> $extra
     */
    public static function tabUrl(string $tab, array $extra = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::areaSlug(self::areaOfTab($tab)), 'tab' => $tab], $extra),
            admin_url('admin.php')
        );
    }

    /**
     * Alte Adressen (`page=churchtools-plugin&tab=calendars`) stehen in
     * Lesezeichen, in der Doku und in Hinweisen, die vor einem Update
     * erschienen sind. Sie fuehren auf die Unterseite des Bereichs, samt
     * allem, was sonst in der Adresse stand (`section`, `event_id`, Filter,
     * `settings-updated`).
     */
    public function redirectLegacyTabUrl(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation, the redirect only rewrites where the same page is shown.
        if (sanitize_key((string) ($_GET['page'] ?? '')) !== self::PAGE_SLUG || !isset($_GET['tab'])) {
            return;
        }

        $tab = sanitize_key((string) $_GET['tab']);

        if (!array_key_exists($tab, self::tabs()) || self::areaOfTab($tab) === 'overview') {
            return;
        }

        $extra = [];

        foreach (wp_unslash($_GET) as $key => $value) {
            if (in_array($key, ['page', 'tab'], true) || !is_scalar($value)) {
                continue;
            }

            $extra[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
        }
        // phpcs:enable

        wp_safe_redirect(self::tabUrl($tab, $extra));
        exit;
    }

    /**
     * One entry per tab; the key doubles as the `tab` query var and as the settings
     * page slug suffix (see registerSettings()) so each tab's <form> only submits its
     * own fields — sanitizeSettings() relies on that to know which keys to touch.
     */
    private static function tabs(): array
    {
        return [
            'status' => __('Übersicht', 'churchtools-plugin'),
            'connection' => __('Verbindung', 'churchtools-plugin'),
            'calendars' => __('Kalender', 'churchtools-plugin'),
            'rooms' => __('Räume', 'churchtools-plugin'),
            'group_list' => __('Gruppenliste', 'churchtools-plugin'),
            'groups' => __('Homepages', 'churchtools-plugin'),
            'group_sync' => __('Synchronisation', 'churchtools-plugin'),
            'group_embed' => __('Einbinden', 'churchtools-plugin'),
            'post_list' => __('Beitragsliste', 'churchtools-plugin'),
            'post_sync' => __('Synchronisation', 'churchtools-plugin'),
            'post_embed' => __('Einbinden', 'churchtools-plugin'),
            'sync' => __('Synchronisation', 'churchtools-plugin'),
            'design' => __('Design', 'churchtools-plugin'),
            'embed' => __('Einbinden', 'churchtools-plugin'),
            // „Terminliste" statt „Events": Der Bereich heisst seit 2026-09-14
            // selbst „Events", ein gleichnamiger Reiter darin sagte nicht, was
            // er zeigt.
            'events' => __('Terminliste', 'churchtools-plugin'),
            'updates' => __('Updates', 'churchtools-plugin'),
            'log' => __('Protokoll', 'churchtools-plugin'),
        ];
    }

    /**
     * Der Reiter muss zum Bereich der Seite gehoeren; sonst gilt der erste
     * Reiter des Bereichs. So kann `page=…-groups&tab=design` nicht die
     * Design-Einstellungen unter „Gruppen" zeigen.
     */
    private static function currentTab(): string
    {
        $areaTabs = self::AREA_TABS[self::currentArea()];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation (which tab to display), not a state change.
        $tab = sanitize_key((string) ($_GET['tab'] ?? ''));

        return in_array($tab, $areaTabs, true) ? $tab : $areaTabs[0];
    }

    /**
     * Purely cosmetic (tab-nav scanability) — keyed the same as tabs(), one dashicon
     * per topic so the tabs read as distinct sections instead of plain text labels.
     */
    private static function tabIcons(): array
    {
        return [
            'status' => 'dashboard',
            'connection' => 'admin-links',
            'calendars' => 'calendar-alt',
            'rooms' => 'location-alt',
            'group_list' => 'list-view',
            'groups' => 'groups',
            'group_sync' => 'update',
            'group_embed' => 'editor-code',
            'post_list' => 'list-view',
            'post_sync' => 'update',
            'post_embed' => 'editor-code',
            'sync' => 'update',
            'design' => 'admin-appearance',
            'embed' => 'editor-code',
            'events' => 'list-view',
            'updates' => 'cloud-upload',
            // media-text statt list-view (schon an "events"/"group_list"
            // vergeben) - dasselbe Dashicon steht bereits am
            // Changelog-Schnelllink im Updates-Tab (UpdatesTab::render()).
            'log' => 'media-text',
        ];
    }

    public function enqueueAssets(string $hook): void
    {
        if (!in_array($hook, $this->pageHooks, true)) {
            return;
        }

        // Nur der Tab „Kalender“ oeffnet einen Medien-Dialog (Standardbild je
        // Kalender) - auf allen uebrigen Tabs waren das bisher rund ein Dutzend
        // Skripte und Stylesheets, die niemand aufruft.
        if (self::currentTab() === 'calendars') {
            wp_enqueue_media();
        }

        wp_enqueue_style('ctp-admin', CTP_PLUGIN_URL . 'assets/css/admin.css', [], CTP_VERSION);

        // Bis 1.38 ein Inline-Skript am Ende von renderPage(). Vor
        // admin-design.js eingereiht, damit die Reihenfolge der Listener am
        // Dokument dieselbe bleibt wie damals (Inline-Block vor dem Footer).
        wp_enqueue_script('ctp-admin', CTP_PLUGIN_URL . 'assets/js/admin.js', [], CTP_VERSION, true);
        wp_localize_script('ctp-admin', 'ctpAdmin', [
            'nonces' => [
                'testConnection' => wp_create_nonce('ctp_test_connection'),
                'fetchCalendars' => wp_create_nonce('ctp_fetch_calendars'),
                'fetchResources' => wp_create_nonce('ctp_fetch_resources'),
                'runSync' => wp_create_nonce('ctp_run_sync'),
                'fetchGroupHomepages' => wp_create_nonce('ctp_fetch_group_homepages'),
                'runGroupSync' => wp_create_nonce('ctp_run_group_sync'),
                'runPostSync' => wp_create_nonce('ctp_run_post_sync'),
                'checkUpdates' => wp_create_nonce('ctp_check_updates'),
            ],
            'labels' => [
                'checking' => __('Prüfe…', 'churchtools-plugin'),
                'connectionOk' => __('Verbindung erfolgreich', 'churchtools-plugin'),
                'connectionFailed' => __('Verbindung fehlgeschlagen', 'churchtools-plugin'),
                'loading' => __('Lade…', 'churchtools-plugin'),
                'loadFailed' => __('Laden fehlgeschlagen', 'churchtools-plugin'),
                'applying' => __('Übernehme…', 'churchtools-plugin'),
                'applied' => __('Übernommen', 'churchtools-plugin'),
                'applyFailed' => __('Übernehmen fehlgeschlagen', 'churchtools-plugin'),
                'syncing' => __('Synchronisiere…', 'churchtools-plugin'),
                'syncFailed' => __('Synchronisation fehlgeschlagen', 'churchtools-plugin'),
                'failed' => __('Fehlgeschlagen', 'churchtools-plugin'),
                'chooseDefaultImage' => __('Standardbild wählen', 'churchtools-plugin'),
                'replace' => __('Ersetzen', 'churchtools-plugin'),
                'chooseImage' => __('Bild wählen', 'churchtools-plugin'),
                'checkFailed' => __('Prüfung fehlgeschlagen', 'churchtools-plugin'),
                'copied' => __('Kopiert!', 'churchtools-plugin'),
            ],
        ]);

        // Own handle rather than reusing Assets::STYLE_HANDLE — that class'
        // enqueue is conditioned on the current *frontend* request using the
        // shortcode/block, an unrelated concern to whether the admin's Design
        // tab preview needs the stylesheet. Loaded after ctp-admin so its
        // .ctp-events rules (needed for the live preview) aren't shadowed by it.
        // Der Bereich „Listen" ist der einzige des Design-Tabs ohne Vorschau -
        // dort braucht es weder das Frontend-Stylesheet noch das Skript, das
        // sie treibt (gleiche Rechnung wie beim Medien-Dialog oben).
        if (self::currentTab() === 'design' && DesignTab::currentDesignSection() !== 'list') {
            wp_enqueue_style('ctp-admin-design', CTP_PLUGIN_URL . 'assets/css/frontend.css', ['ctp-admin'], CTP_VERSION);
            wp_enqueue_script('ctp-admin-design', CTP_PLUGIN_URL . 'assets/js/admin-design.js', [], CTP_VERSION, true);
            // Every other user-facing string in this plugin is translated in PHP
            // (__()/esc_html_e()), not duplicated in JS — the labels the script
            // needs for dynamically-added spacer/divider list items (see
            // DesignTab::renderElementOrderField()/separatorLabels()) go through the same path.
            wp_localize_script('ctp-admin-design', 'ctpDesignLabels', array_merge(
                DesignTab::separatorLabels(),
                ['remove' => __('Entfernen', 'churchtools-plugin')]
            ));
        }
    }

    public function registerSettings(): void
    {
        register_setting(self::PAGE_SLUG, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeSettings'],
            'default' => Settings::defaults(),
        ]);

        $connectionPage = self::PAGE_SLUG . '_connection';
        // Kein $calendarsPage mehr: der Tab „Kalender“ rendert seine Auswahl
        // selbst (siehe CalendarsTab::render()), weil die Settings-API-Tabelle
        // sie in eine Formularzelle gepresst hat, in der weder die
        // Kalenderfarbe noch die Terminzahl Platz fand. Die Feldnamen sind
        // dieselben geblieben, sanitizeCalendars() merkt davon nichts.
        $syncPage = self::PAGE_SLUG . '_sync';

        add_settings_section('ctp_instance', __('ChurchTools-Instanz', 'churchtools-plugin'), '__return_false', $connectionPage);
        add_settings_field('instance', __('Instanz', 'churchtools-plugin'), [$this, 'renderInstanceField'], $connectionPage, 'ctp_instance');

        add_settings_section('ctp_api', __('API-Key & Verbindungstest', 'churchtools-plugin'), '__return_false', $connectionPage);
        add_settings_field('api_key', __('API-Key', 'churchtools-plugin'), [$this, 'renderApiKeyField'], $connectionPage, 'ctp_api');

        // Section-Callback statt '__return_false': er rendert genau zwischen
        // Ueberschrift und Feldtabelle - die Stelle, an der die Aktionsleiste
        // auf jedem anderen Tab auch steht (siehe renderActionBar()).
        add_settings_section('ctp_sync', __('Sync-Einstellungen', 'churchtools-plugin'), [self::class, 'renderSyncIntro'], $syncPage);
        add_settings_field('sync_interval', __('Sync-Intervall', 'churchtools-plugin'), [$this, 'renderSyncIntervalField'], $syncPage, 'ctp_sync');
        add_settings_field('sync_days_ahead', __('Sync-Zeitraum (Tage in die Zukunft)', 'churchtools-plugin'), [$this, 'renderSyncDaysAheadField'], $syncPage, 'ctp_sync');
        add_settings_field('retention_days', __('Aufbewahrung nach Event-Ende (Tage)', 'churchtools-plugin'), [$this, 'renderRetentionField'], $syncPage, 'ctp_sync');

        // Gilt fuer Termine *und* Gruppen (siehe uninstall.php) und stand bis
        // 2026-09-15 trotzdem unter „Events → Synchronisation". Jetzt unter
        // „Einstellungen → Updates", wo es um das Plugin als Ganzes geht.
        add_settings_section('ctp_uninstall', __('Deinstallieren', 'churchtools-plugin'), '__return_false', self::PAGE_SLUG . '_uninstall');
        add_settings_field('keep_data_on_uninstall', __('Beim Deinstallieren', 'churchtools-plugin'), [$this, 'renderKeepDataOnUninstallField'], self::PAGE_SLUG . '_uninstall', 'ctp_uninstall');

        DesignTab::registerFields();
    }

    /**
     * The "Verbindung testen" / "Kalender laden" buttons should test whatever is
     * currently typed into the instance/API-key fields — including a value the
     * admin hasn't clicked "Speichern" for yet — falling back to the stored value
     * only where a field was left empty (mirrors the same "empty means keep
     * existing" rule sanitizeSettings() uses when saving the API key).
     *
     * Mit einer Ausnahme (Sicherheits-Review 2026-09-14): Der gespeicherte Key
     * geht nur an die gespeicherte Instanz. Vorher liess sich eine andere
     * Instanz eintippen und der gespeicherte Key dazunehmen - er ging dann an
     * einen fremden `*.church.tools`-Host, und wer dort mitliest, hatte ihn.
     * Wer eine andere Instanz testen will, tippt deren Key mit ein.
     *
     * @return array{instance: string, api_key: string, base_url: string, error: string}
     */
    private static function effectiveConnection(): array
    {
        $stored = Settings::get();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every caller runs check_ajax_referer() before reaching this helper.
        $instance = self::sanitizeInstance((string) wp_unslash($_POST['instance'] ?? ''));
        if ($instance === '') {
            $instance = $stored['instance'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
        $apiKey = trim((string) wp_unslash($_POST['api_key'] ?? ''));
        $error = '';

        if ($apiKey === '') {
            if (ApiKey::isConfigured() && $stored['instance'] !== '' && $instance !== $stored['instance']) {
                $error = sprintf(
                    /* translators: %s: name of the stored ChurchTools instance */
                    __('Der hinterlegte API-Key gehört zur Instanz „%s“ und wird an keine andere geschickt. Für eine andere Instanz bitte deren Key mit eingeben.', 'churchtools-plugin'),
                    $stored['instance']
                );
            } elseif (ApiKey::decryptionFailed()) {
                $error = ApiKey::decryptionErrorMessage();
            } else {
                $apiKey = ApiKey::current();
            }
        }

        if ($error === '' && ($instance === '' || $apiKey === '')) {
            $error = __('Bitte Instanz und API-Key eingeben.', 'churchtools-plugin');
        }

        return [
            'instance' => $instance,
            'api_key' => $error === '' ? $apiKey : '',
            'base_url' => Settings::buildBaseUrl($instance),
            'error' => $error,
        ];
    }

    /**
     * Was gespeichert wird, wenn im Formular ein API-Key steht.
     *
     * Ein bereits verschluesselter Wert wird durchgereicht statt erneut
     * verschluesselt: Beim allerersten Speichern einer Option laeuft dieser
     * Sanitizer zweimal (update_option() sanitisiert und reicht an
     * add_option() weiter, das erneut sanitisiert - siehe Crypto::PREFIX),
     * der zweite Durchlauf bekommt also die Ausgabe des ersten zu sehen. Ohne
     * diese Abfrage lag der Token danach doppelt verschluesselt in der
     * Datenbank, und jede Anfrage an ChurchTools scheiterte mit
     * "401: No valid token" - waehrend "Verbindung testen" gruen blieb, weil
     * der Test den getippten Wert nimmt und nicht den gespeicherten (siehe
     * effectiveConnection()).
     *
     * Leer heisst weiterhin "bestehenden Wert behalten": Das Feld wird nie mit
     * dem gespeicherten Token vorbefuellt (siehe renderApiKeyField()), ein
     * Speichern aus einem anderen Tab darf ihn also nicht loeschen.
     */
    private static function apiKeyToStore(string $submitted, string $existing): string
    {
        if ($submitted === '') {
            return $existing;
        }

        return Crypto::isCiphertext($submitted) ? $submitted : Crypto::encrypt($submitted);
    }

    /**
     * Each admin-UI tab is its own <form> and only posts the fields it renders, so
     * $input only ever contains a subset of the keys below (see tabs()). A key that
     * is entirely absent means "this tab wasn't submitted", not "clear this value" —
     * every branch here must fall back to $existing, not to a hardcoded default.
     *
     * $input is nullable because options.php passes null when the tab's <form> had
     * no ctp_settings[...] field at all — e.g. the Kalender tab before the first
     * successful fetch renders no inputs, only the "Kalender laden" button.
     */
    public static function sanitizeSettings(?array $input): array
    {
        // Frisch aus ChurchTools geholte Listen und Migrationen schreiben am
        // Formular vorbei - siehe Settings::writeUnsanitized().
        if (Settings::isWritingUnsanitized() && $input !== null) {
            return $input;
        }

        $input ??= [];
        $existing = Settings::get();
        $apiKey = trim((string) ($input['api_key'] ?? ''));

        $syncInterval = $existing['sync_interval'];
        if (array_key_exists('sync_interval', $input) && in_array($input['sync_interval'], Installer::SYNC_INTERVALS, true)) {
            $syncInterval = $input['sync_interval'];
        }

        $designPreset = $existing['design_preset'];
        if (array_key_exists('design_preset', $input) && in_array($input['design_preset'], DesignPreset::PRESETS, true)) {
            $designPreset = $input['design_preset'];
        }

        $cornerStyle = $existing['corner_style'];
        if (array_key_exists('corner_style', $input) && in_array($input['corner_style'], CardDesign::CORNER_STYLES, true)) {
            $cornerStyle = $input['corner_style'];
        }

        $clickBehavior = $existing['click_behavior'];
        if (array_key_exists('click_behavior', $input) && in_array($input['click_behavior'], ['none', 'popup', 'page'], true)) {
            $clickBehavior = $input['click_behavior'];
        }

        // Muss eine veroeffentlichte Seite sein, sonst 0: Ein Entwurf oder ein
        // Beitrag haette entweder gar keine oeffentliche Adresse oder eine, die
        // WordPress schon selbst belegt. Die Pruefung steht hier und nicht erst
        // beim Rendern, damit die Einstellung nicht still etwas anderes
        // bedeutet, als sie anzeigt.
        $detailPageId = $existing['detail_page_id'];
        if (array_key_exists('detail_page_id', $input)) {
            $candidate = (int) $input['detail_page_id'];
            $usable = $candidate > 0
                && get_post_type($candidate) === 'page'
                && get_post_status($candidate) === 'publish'
                // Startseite und Beitragsseite scheiden aus: Ihre
                // Rewrite-Regel laege ueber der halben Website
                // (Frontend\EventDetailPage::mayHostEvents()).
                && $candidate !== (int) get_option('page_on_front')
                && $candidate !== (int) get_option('page_for_posts');
            $detailPageId = $usable ? $candidate : 0;
        }

        $mediaAspectRatio = $existing['media_aspect_ratio'];
        if (array_key_exists('media_aspect_ratio', $input) && array_key_exists($input['media_aspect_ratio'], CardDesign::MEDIA_ASPECT_RATIOS)) {
            $mediaAspectRatio = $input['media_aspect_ratio'];
        }

        $accentColor = $existing['accent_color'];
        if (array_key_exists('accent_color', $input)) {
            $sanitizedColor = sanitize_hex_color((string) $input['accent_color']);
            if (!empty($sanitizedColor)) {
                $accentColor = $sanitizedColor;
            }
        }

        $buttonColor = $existing['button_color'];
        if (array_key_exists('button_color', $input)) {
            $sanitizedButtonColor = sanitize_hex_color((string) $input['button_color']);
            if (!empty($sanitizedButtonColor)) {
                $buttonColor = $sanitizedButtonColor;
            }
        }

        // Leer ist ein gueltiger Wert und bedeutet „kein Kennzeichen" - das
        // leere Feld ist der Ausschalter. Deshalb array_key_exists() und nicht
        // eine Pruefung auf einen nichtleeren String: Sonst liesse sich das
        // Kennzeichen nie wieder abschalten.
        $liveLabel = $existing['live_label'] ?? '';
        if (array_key_exists('live_label', $input)) {
            $liveLabel = LiveBadge::sanitizeLabel((string) $input['live_label']);
        }

        return [
            'instance' => array_key_exists('instance', $input)
                ? self::sanitizeInstance((string) $input['instance'])
                : $existing['instance'],
            'api_key' => self::apiKeyToStore($apiKey, $existing['api_key']),
            'calendars' => array_key_exists('calendars', $input)
                ? self::sanitizeCalendars((array) $input['calendars'], $existing['calendars'])
                : $existing['calendars'],
            'resources' => array_key_exists('resources', $input)
                ? self::sanitizeResources((array) $input['resources'], $existing['resources'] ?? [])
                : ($existing['resources'] ?? []),
            'rooms_mode' => self::sanitizeRoomsMode($input, $existing),
            'sync_interval' => $syncInterval,
            'sync_days_ahead' => array_key_exists('sync_days_ahead', $input)
                ? max(1, (int) $input['sync_days_ahead'])
                : $existing['sync_days_ahead'],
            'retention_days' => array_key_exists('retention_days', $input)
                ? max(0, (int) $input['retention_days'])
                : $existing['retention_days'],
            'keep_data_on_uninstall' => array_key_exists('keep_data_on_uninstall', $input)
                ? (bool) $input['keep_data_on_uninstall']
                : $existing['keep_data_on_uninstall'],
            'design_preset' => $designPreset,
            'element_order' => array_key_exists('element_order', $input)
                ? self::sanitizeElementOrder(self::orderInput($input['element_order']))
                : $existing['element_order'],
            'corner_style' => $cornerStyle,
            // Checkbox group: DesignTab::renderFieldVisibilityField() prints a hidden
            // "[]" marker before the checkboxes (same trick as
            // keep_data_on_uninstall below) so an all-unchecked submit still
            // arrives as an empty array, not a missing key.
            'hidden_elements' => array_key_exists('hidden_elements', $input)
                ? CardDesign::sanitizeHiddenElements((array) $input['hidden_elements'])
                : $existing['hidden_elements'],
            'media_aspect_ratio' => $mediaAspectRatio,
            'accent_color_enabled' => array_key_exists('accent_color_enabled', $input)
                ? (bool) $input['accent_color_enabled']
                : $existing['accent_color_enabled'],
            'accent_color' => $accentColor,
            'button_color_enabled' => array_key_exists('button_color_enabled', $input)
                ? (bool) $input['button_color_enabled']
                : $existing['button_color_enabled'],
            'button_color' => $buttonColor,
            'live_label' => $liveLabel,
            'click_behavior' => $clickBehavior,
            'detail_page_id' => $detailPageId,
            'detail_element_order' => array_key_exists('detail_element_order', $input)
                ? self::sanitizeDetailElementOrder(self::orderInput($input['detail_element_order']))
                : $existing['detail_element_order'],
            // Checkbox mit vorangestelltem Hidden-Feld, wie
            // keep_data_on_uninstall — ohne das käme ein abgehaktes Kästchen
            // gar nicht erst im $input an und würde als „unverändert" gelesen.
            'detail_share_enabled' => array_key_exists('detail_share_enabled', $input)
                ? (bool) $input['detail_share_enabled']
                : $existing['detail_share_enabled'],
            'detail_ics_enabled' => array_key_exists('detail_ics_enabled', $input)
                ? (bool) $input['detail_ics_enabled']
                : $existing['detail_ics_enabled'],
            'detail_subscribe_enabled' => array_key_exists('detail_subscribe_enabled', $input)
                ? (bool) $input['detail_subscribe_enabled']
                : $existing['detail_subscribe_enabled'],
            'paging_months' => array_key_exists('paging_months', $input)
                ? EventWindow::sanitizeMonths((int) $input['paging_months'])
                : $existing['paging_months'],
        ];
    }

    /**
     * Beide Reihenfolge-Felder kommen als kommagetrennter String aus ihrem
     * Hidden-Input - beim allerersten Speichern einer Option laeuft dieser
     * Sanitizer aber zweimal (siehe apiKeyToStore()), und im zweiten
     * Durchlauf steht dort die bereits zerlegte Liste des ersten. Ohne diese
     * Umwandlung machte (string) daraus "Array": eine PHP-Warnung mitten in
     * der Antwort auf das Speichern, und aus einer gerade eingestellten
     * Anordnung die Standardanordnung (siehe sanitizeElementOrder(), das
     * einen unbekannten Wert bewusst auf CardDesign::DEFAULT_ORDER schnappen
     * laesst).
     */
    private static function orderInput(mixed $raw): string
    {
        return is_array($raw) ? implode(',', array_map('strval', $raw)) : (string) $raw;
    }

    /**
     * The Design tab's hidden input submits the drag&drop order (the fixed
     * CardDesign::ELEMENT_KEYS plus any admin-inserted spacer-/divider-prefixed
     * separators, see DesignTab::renderElementOrderField()) as a comma-separated string.
     * Unlike every other field in this method, an invalid value here does NOT
     * fall back to $existing — a *present but malformed* value (JS bug,
     * tampered POST, a duplicate/missing/unknown fixed key) snaps straight to
     * CardDesign::DEFAULT_ORDER instead. Falling back to $existing would risk
     * silently keeping a half-applied permutation; the known-good default is
     * the safer failure mode. The ordinary "key entirely absent from $input"
     * case (a different tab's form was submitted) still falls back to
     * $existing in sanitizeSettings() above, same as every other field.
     */
    private static function sanitizeElementOrder(string $raw): array
    {
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        // Drops anything that isn't a plain key/instance-id string (JS only ever
        // generates keys matching this shape) before the permutation check below,
        // rather than letting one garbage entry invalidate an otherwise-valid order.
        $keys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => (bool) preg_match('/^[a-z0-9-]+$/', $key)
        ));

        return CardDesign::isValidOrder($keys) ? $keys : CardDesign::DEFAULT_ORDER;
    }

    /**
     * Same "present but malformed value snaps to the default, absent value falls
     * back to $existing" rule as sanitizeElementOrder() above, for the detail
     * view's own (separator-free) key set.
     *
     * Der upgradeOrder()-Aufruf vor der Prüfung ist der Unterschied zur
     * Kachel-Fassung, und er gilt einem schmalen, aber stillen Fall: Ein
     * Formular, das *vor* einer Erweiterung des Schlüsselsatzes gerendert
     * wurde, schickt die alte Reihenfolge ab — aus einem Browser-Tab, der seit
     * gestern offen ist, oder aus einer zwischengespeicherten Admin-Seite.
     * Ohne die Verbreiterung schnappte das auf DEFAULT_ORDER, und der
     * Betreiber verlöre beim Speichern einer ganz anderen Einstellung seine
     * eingestellte Anordnung. Betrifft „meta" aus 1.x genauso wie „share" aus
     * 1.17.0.
     */
    private static function sanitizeDetailElementOrder(string $raw): array
    {
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        $keys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => (bool) preg_match('/^[a-z0-9-]+$/', $key)
        ));
        $keys = DetailDesign::upgradeOrder($keys);

        return DetailDesign::isValidOrder($keys) ? $keys : DetailDesign::DEFAULT_ORDER;
    }

    /**
     * Accepts either a bare instance name ("musterkirche") or a full URL a user
     * might paste by habit ("https://musterkirche.church.tools/") and normalizes
     * both to "musterkirche".
     */
    private static function sanitizeInstance(string $raw): string
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('#^https?://#i', '', $raw);
        $raw = (string) preg_replace('#\.church\.tools.*$#i', '', $raw);
        $raw = trim($raw, "/ \t\n\r\0\x0B");

        return (string) preg_replace('/[^a-z0-9-]/', '', strtolower($raw));
    }

    /**
     * The calendar table always posts an entry per known calendar (the checkbox is
     * the only field that can be missing when unchecked), so we only trust rows for
     * IDs we already know about from a previous fetch — the `name` label itself is
     * never taken from user input, it is carried over from $existing.
     */
    /**
     * Zwilling von sanitizeCalendars(): Nur IDs, die schon bekannt sind, kommen
     * durch - alles andere waere ein Formularfeld, das jemand erfunden hat. Name
     * und Sortierschluessel sind keine Eingabefelder, sie stammen aus
     * ChurchTools und werden nur mitgetragen; einstellbar ist genau der Haken.
     */
    private static function sanitizeResources(array $input, array $existing): array
    {
        $resources = [];

        foreach ($input as $id => $row) {
            $id = (int) $id;

            if (!isset($existing[$id])) {
                continue;
            }

            $resources[$id] = [
                'name' => (string) $existing[$id]['name'],
                'enabled' => !empty($row['enabled']),
                'sort_key' => (int) ($existing[$id]['sort_key'] ?? 0),
            ];
        }

        return $resources;
    }

    private static function sanitizeCalendars(array $input, array $existing): array
    {
        $calendars = [];

        foreach ($input as $id => $row) {
            $id = (int) $id;

            if (!isset($existing[$id])) {
                continue;
            }

            $color = sanitize_hex_color((string) ($row['color'] ?? ''));

            $calendars[$id] = [
                'name' => $existing[$id]['name'],
                'enabled' => !empty($row['enabled']),
                'color' => $color ?: $existing[$id]['color'],
                // Not user-editable here (no form field for it) — always carried
                // over from $existing, where mergeCalendars() keeps it in sync
                // with ChurchTools' own color on every "Kalender laden".
                'default_color' => $existing[$id]['default_color'] ?? $existing[$id]['color'],
                'default_image_id' => absint($row['default_image_id'] ?? 0),
                // Ebenfalls kein Formularfeld: Das ist ChurchTools' Angabe,
                // hier waere sie nur eine Meinung. mergeCalendars() haelt sie
                // bei jedem „Kalender laden" aktuell.
                'is_public' => (bool) ($existing[$id]['is_public'] ?? true),
            ];
        }

        return $calendars;
    }

    public function renderInstanceField(): void
    {
        printf(
            '<span class="ctp-instance-row">'
            . '<code>https://</code>'
            . '<input type="text" id="ctp-instance" name="%1$s[instance]" value="%2$s" class="regular-text" placeholder="musterkirche" pattern="[a-z0-9-]+" />'
            . '<code>.church.tools</code>'
            . '</span>'
            . '<p class="description">%3$s</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr(Settings::get()['instance']),
            esc_html__('Nur der Instanz-Name eintragen, z. B. „musterkirche“ für https://musterkirche.church.tools', 'churchtools-plugin')
        );
    }

    public function renderApiKeyField(): void
    {
        $fromConfig = ApiKey::isFromConfig();
        $hasKey = ApiKey::isConfigured();

        if ($fromConfig) {
            $placeholder = __('Aus der Serverkonfiguration (CTP_API_KEY)', 'churchtools-plugin');
            $description = __('Der Key steht in wp-config.php oder in einer Umgebungsvariable und nicht in der Datenbank. Ändern lässt er sich nur dort. Der Test prüft ihn mit der Instanz aus dem Feld oben.', 'churchtools-plugin');
        } else {
            $placeholder = $hasKey ? __('Hinterlegt – zum Ändern neuen Key eingeben', 'churchtools-plugin') : '';
            $description = __('Der Test fragt ChurchTools mit Instanz und Key aus den Feldern oben ab – auch ungespeichert. Ein leeres Key-Feld greift auf den gespeicherten Key zurück, aber nur für die gespeicherte Instanz. Empfohlen: ein eigener ChurchTools-Benutzer, der nur die übernommenen Kalender und Räume sehen darf. Wer den Key nicht in der Datenbank haben will, trägt ihn als Konstante CTP_API_KEY in wp-config.php ein.', 'churchtools-plugin');
        }

        // „Verbindung testen“ bleibt bewusst am Feld statt in der
        // Aktionsleiste unter der Ueberschrift: der Knopf prueft genau das,
        // was gerade in diesen beiden Feldern steht - auch ungespeichert
        // (siehe effectiveConnection()). Die Rueckmeldung nutzt trotzdem
        // dasselbe .ctp-inline-status-Bauteil wie jede andere Aktion im
        // Backend.
        printf(
            '<span class="ctp-field-with-button">'
            . '<input type="password" id="ctp-api-key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s"%5$s />'
            . '<button type="button" class="button" id="ctp-test-connection">%3$s</button>'
            . '<span class="ctp-inline-status" id="ctp-test-connection-result" role="status" aria-live="polite"></span>'
            . '</span>'
            . '<p class="description">%4$s</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr($placeholder),
            esc_html__('Verbindung testen', 'churchtools-plugin'),
            esc_html($description),
            $fromConfig ? ' disabled' : ''
        );
    }

    /**
     * German labels for Installer::SYNC_INTERVALS — keyed by the WP-Cron
     * recurrence name the schedule is actually created with. Eine Liste fuer
     * Termine und Gruppen, wie die Auswahl selbst.
     *
     * @return array<string, string>
     */
    public static function syncIntervalLabels(): array
    {
        return [
            'hourly' => __('Stündlich', 'churchtools-plugin'),
            'twicedaily' => __('Zweimal täglich', 'churchtools-plugin'),
            'daily' => __('Täglich', 'churchtools-plugin'),
            'weekly' => __('Wöchentlich', 'churchtools-plugin'),
        ];
    }

    /**
     * Das Feld „Sync-Intervall" fuer Termine und Gruppen - dieselbe Auswahl,
     * derselbe Hinweis zu WP-Cron. $extraHint steht als eigener Absatz
     * darunter, fuer das, was nur einen der beiden betrifft.
     */
    public static function renderIntervalSelect(string $name, string $current, string $extraHint = ''): void
    {
        echo '<select name="' . esc_attr($name) . '">';
        foreach (self::syncIntervalLabels() as $value => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">'
            . esc_html__('Beim Speichern wird der WP-Cron-Termin auf dieses Intervall umgestellt. Wann er tatsächlich feuert, hängt vom Seitenaufkommen ab – siehe Hinweis zu WP-Cron in der readme.txt.', 'churchtools-plugin')
            . '</p>';

        if ($extraHint !== '') {
            echo '<p class="description">' . esc_html($extraHint) . '</p>';
        }
    }

    public function renderSyncIntervalField(): void
    {
        self::renderIntervalSelect(self::OPTION_KEY . '[sync_interval]', Settings::get()['sync_interval']);
    }

    public function renderSyncDaysAheadField(): void
    {
        printf(
            '<input type="number" min="1" name="%1$s[sync_days_ahead]" value="%2$s" class="small-text" /> %3$s',
            esc_attr(self::OPTION_KEY),
            esc_attr((string) Settings::get()['sync_days_ahead']),
            esc_html__('Tage', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Wie weit im Voraus Termine synchronisiert werden. Betrifft nur den Sync-Zeitraum, nicht die Aufbewahrung vergangener Termine.', 'churchtools-plugin')
            . '</p>';
    }

    public function renderRetentionField(): void
    {
        printf(
            '<input type="number" min="0" name="%1$s[retention_days]" value="%2$s" class="small-text" /> %3$s',
            esc_attr(self::OPTION_KEY),
            esc_attr((string) Settings::get()['retention_days']),
            esc_html__('Tage', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Wie lange bereits vergangene Termine noch gespeichert bleiben, bevor der Sync sie entfernt. 0 = sofort nach Ende des Termins löschen.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Hidden field before the checkbox so an unchecked box still posts
     * "0" — otherwise sanitizeSettings()'s array_key_exists() check would
     * see the key as entirely absent and keep the previous (possibly
     * checked) value instead of actually unchecking it.
     */
    public function renderKeepDataOnUninstallField(): void
    {
        printf('<input type="hidden" name="%1$s[keep_data_on_uninstall]" value="0" />', esc_attr(self::OPTION_KEY));
        printf(
            '<label><input type="checkbox" name="%1$s[keep_data_on_uninstall]" value="1" %2$s /> %3$s</label>',
            esc_attr(self::OPTION_KEY),
            checked(!empty(Settings::get()['keep_data_on_uninstall']), true, false),
            esc_html__('Termine, Gruppen, importierte Bilder und Einstellungen beim Deinstallieren behalten', 'churchtools-plugin')
        );
        echo '<p class="description">'
            . esc_html__('Gilt nur für „Deinstallieren“ (Plugin löschen), nicht für „Deaktivieren“. Standardmäßig aus, damit ein versehentliches Löschen keine Daten hinterlässt, die niemand mehr sieht.', 'churchtools-plugin')
            . '</p>';
    }

    /**
     * Kopf des Sync-Tabs: dieselbe Aktionsleiste wie auf der Uebersicht und im
     * Tab „Kalender“.
     *
     * „Jetzt synchronisieren“ gab es bisher nur auf der Uebersicht. Wer hier
     * gerade Intervall oder Zeitraum geaendert hat, will das Ergebnis sofort
     * sehen und musste dafuer den Tab wechseln.
     */
    public static function renderSyncIntro(): void
    {
        self::renderSyncHead(
            'ctp-run-sync',
            SyncHealthNotice::eventProblem(),
            SyncEngine::getImageWarning(),
            'events'
        );
    }

    /**
     * Der Kopf beider Reiter „Synchronisation", fuer Termine und Gruppen
     * gleich: Knopf, dann was am Abgleich gerade nicht stimmt (Fehler,
     * fehlender Zeitplan, ueberfaellig - dieselbe Auskunft wie der Hinweis
     * auf den uebrigen Backend-Seiten), dann die Bild-Warnung.
     *
     * @param array{type: string, message: string}|null            $problem
     * @param array{time: string, count: int, reasons: string}|null $imageWarning
     * @param 'events'|'groups'|'posts'                            $subject
     */
    public static function renderSyncHead(string $buttonId, ?array $problem, ?array $imageWarning, string $subject): void
    {
        self::renderActionBar(
            $buttonId,
            __('Jetzt synchronisieren', 'churchtools-plugin'),
            __('Läuft sofort, unabhängig vom Intervall. Änderungen unten vorher speichern.', 'churchtools-plugin')
        );

        if ($problem !== null) {
            printf(
                '<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
                esc_attr($problem['type']),
                esc_html($problem['message'])
            );
        }

        self::renderImageWarning($imageWarning, $subject);
    }

    /**
     * Reference panel for the design tab: the design settings above (element
     * order, corner style) apply to every [ctp_events] shortcode automatically,
     * so this is a natural place to also show how to actually place one. The
     * first example uses a real, currently-enabled calendar when one exists
     * (same idea as the live example already shown at the bottom of the
     * Kalender tab) instead of a made-up placeholder name.
     */
    /**
     * Die Leiste am Fuss jedes Einstellungsformulars. Sie klebt am unteren
     * Fensterrand,
     * und das ist der ganze Zweck: Der Speichern-Knopf stand unter allem
     * anderen. Im Design-Tab war das am deutlichsten — wer oben zwischen den
     * vier Vorlagen wechselte, sah ihn nicht, und die Vorschauen daneben
     * schalten sofort um; es sah also aus, als waere schon gespeichert. Auf
     * den uebrigen Tabs ist derselbe Knopf dieselbe Sucherei, nur ohne die
     * Verwechslung obendrauf.
     *
     * Der Zustand daneben ist keine Verzierung, sondern die Antwort auf genau
     * diese Verwechslung: „Nicht gespeicherte Aenderungen", sobald ein Feld
     * angefasst wurde (assets/js/admin-design.js setzt die Klasse).
     */
    public static function renderSaveBar(): void
    {
        ?>
        <div class="ctp-save-bar">
            <p class="ctp-save-bar__state">
                <span class="ctp-save-bar__saved"><?php esc_html_e('Keine offenen Änderungen', 'churchtools-plugin'); ?></span>
                <span class="ctp-save-bar__dirty"><?php esc_html_e('Nicht gespeicherte Änderungen', 'churchtools-plugin'); ?></span>
            </p>
            <?php submit_button(__('Änderungen speichern', 'churchtools-plugin'), 'primary', 'submit', false); ?>
        </div>
        <?php
    }

    /**
     * Die Zahlen, aus denen jede Statuszeile im Backend gebaut wird - einmal
     * an einer Stelle berechnet, damit „Letzte Synchronisation“ auf der
     * Uebersicht, im Tab „Verbindung“ und im Tab „Synchronisation“ nicht drei
     * verschiedene Antworten geben kann. Vorher stand dieselbe Rechnung nur in
     * OverviewTab::render(), und jeder weitere Tab haette sie kopieren
     * muessen.
     *
     * @return array<string, mixed>
     */
    public static function statusFacts(): array
    {
        $settings = Settings::get();
        $calendars = $settings['calendars'];
        $enabled = array_filter($calendars, static fn (array $calendar): bool => !empty($calendar['enabled']));
        $lastSync = (string) get_option('ctp_last_sync', '');
        $nextSync = wp_next_scheduled('ctp_run_sync');
        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');

        $apiKey = ApiKey::current();

        return [
            'settings' => $settings,
            'calendars' => $calendars,
            'calendar_count' => count($calendars),
            'enabled_count' => count($enabled),
            'configured' => $settings['instance'] !== '' && $apiKey !== '',
            // Hinterlegt, aber nicht mehr lesbar - der Befund nach einer
            // AUTH_KEY-Rotation (siehe apiKeyDecryptionFailed()). Hier aus dem
            // bereits entschluesselten Wert abgeleitet, statt ein zweites Mal
            // durch Crypto::decrypt() zu gehen.
            'api_key_broken' => ApiKey::decryptionFailed(),
            'last_sync' => $lastSync,
            'last_sync_label' => $lastSync !== ''
                ? (string) mysql2date($dateFormat, $lastSync)
                : __('noch nie', 'churchtools-plugin'),
            // wp_date(), nicht mysql2date(): wp_next_scheduled() liefert einen
            // UTC-Zeitstempel, und wp_date() ist die Funktion, die so einen
            // Zeitstempel in der Zeitzone der Seite ausgibt.
            'next_sync_label' => $nextSync !== false
                ? (string) wp_date($dateFormat, $nextSync)
                : __('nicht geplant', 'churchtools-plugin'),
            'next_sync_scheduled' => $nextSync !== false,
            'date_format' => $dateFormat,
        ];
    }

    /**
     * Die eine Statuszeile, die auf jedem Tab gleich aussieht.
     *
     * Vorher gab es dieses Kachelraster zweimal wortgleich im Quelltext (Tab
     * „Uebersicht“ und Tab „Events“) und auf allen uebrigen Tabs gar nicht -
     * wer im Tab „Kalender“ oder „Synchronisation“ wissen wollte, wann zuletzt
     * etwas importiert wurde, musste dafuer den Tab wechseln. Jetzt baut jeder
     * Tab, an dem Daten aus ChurchTools hereinkommen, seine Zeile aus derselben
     * Funktion, und sie steht ueberall an derselben Stelle: direkt unter der
     * Tab-Navigation, ueber dem eigentlichen Inhalt.
     *
     * Die Breite ist die der Panels darunter, damit alles auf derselben
     * rechten Kante endet. Bis 1.6.0 gab es dafuer einen $wide-Schalter, weil
     * Formulartabs 960px breit waren und der Rest 1400px; seit es nur noch
     * eine Panelbreite gibt, ist der Schalter entfallen.
     *
     * `swatch` ersetzt bei Bedarf das Dashicon durch einen Farbpunkt; die
     * Akzentfarbe im Tab „Design“ ist als Hex-Code allein nicht ablesbar.
     *
     * @param array<int, array{icon: string, value: string, label: string, tone?: string, swatch?: string}> $cards
     */
    public static function renderStatStrip(array $cards): void
    {
        if ($cards === []) {
            return;
        }
        ?>
        <div class="ctp-status-strip">
            <div class="ctp-stat-grid">
                <?php foreach ($cards as $card) : ?>
                    <?php $tone = $card['tone'] ?? ''; ?>
                    <div class="ctp-stat-card<?php echo $tone !== '' ? ' ctp-stat-card--' . esc_attr($tone) : ''; ?>">
                        <?php if (!empty($card['swatch'])) : ?>
                            <span class="ctp-stat-card__swatch" style="background-color:<?php echo esc_attr($card['swatch']); ?>" aria-hidden="true"></span>
                        <?php else : ?>
                            <span class="dashicons dashicons-<?php echo esc_attr($card['icon']); ?>" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="ctp-stat-card__value"><?php echo esc_html($card['value']); ?></span>
                        <span class="ctp-stat-card__label"><?php echo esc_html($card['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Statuszeile fuer den Tab, auf dem sie gerade gebraucht wird. Ein Schalter
     * statt fuenf Aufrufstellen, damit renderPage() nicht fuer jeden Tab eine
     * eigene Zeile Sonderbehandlung bekommt und ein neuer Tab hier automatisch
     * mitgedacht wird.
     */
    private static function renderTabStatus(string $tab): void
    {
        $facts = self::statusFacts();
        $settings = $facts['settings'];

        switch ($tab) {
            case 'status':
                self::renderStatStrip(self::statusCardsOverview($facts));

                return;
            case 'connection':
                self::renderStatStrip([
                    [
                        'icon' => 'admin-links',
                        'value' => $settings['instance'] !== '' ? $settings['instance'] : '—',
                        'label' => __('Instanz', 'churchtools-plugin'),
                        'tone' => $settings['instance'] !== '' ? 'ok' : 'warn',
                    ],
                    self::apiKeyCard($facts),
                    [
                        'icon' => 'calendar-alt',
                        'value' => sprintf(
                            /* translators: 1: number of enabled calendars, 2: total number of known calendars */
                            __('%1$d von %2$d', 'churchtools-plugin'),
                            $facts['enabled_count'],
                            $facts['calendar_count']
                        ),
                        'label' => __('Aktive Kalender', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'update',
                        'value' => $facts['last_sync_label'],
                        'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                    ],
                ]);

                return;
            case 'calendars':
                $counts = CalendarsTab::calendarEventCounts();
                $fetched = (string) get_option(CalendarList::FETCHED_OPTION, '');
                $enabledIds = Settings::getEnabledCalendarIds();
                $eventsFromEnabled = 0;
                foreach ($enabledIds as $enabledId) {
                    $eventsFromEnabled += $counts[$enabledId]['total'] ?? 0;
                }

                self::renderStatStrip([
                    [
                        'icon' => 'calendar-alt',
                        'value' => (string) $facts['calendar_count'],
                        'label' => __('Bekannte Kalender', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'yes-alt',
                        'value' => sprintf(
                            /* translators: 1: number of enabled calendars, 2: total number of known calendars */
                            __('%1$d von %2$d', 'churchtools-plugin'),
                            $facts['enabled_count'],
                            $facts['calendar_count']
                        ),
                        'label' => __('Zur Synchronisation aktiviert', 'churchtools-plugin'),
                        'tone' => $facts['enabled_count'] > 0 ? 'ok' : 'warn',
                    ],
                    [
                        'icon' => 'list-view',
                        'value' => (string) $eventsFromEnabled,
                        'label' => __('Termine aus aktiven Kalendern', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'download',
                        'value' => $fetched !== ''
                            ? (string) mysql2date($facts['date_format'], $fetched)
                            : __('noch nie', 'churchtools-plugin'),
                        'label' => __('Kalenderliste zuletzt geladen', 'churchtools-plugin'),
                    ],
                ]);

                return;
            case 'sync':
                self::renderStatStrip([
                    [
                        'icon' => 'update',
                        'value' => $facts['last_sync_label'],
                        'label' => __('Letzte Synchronisation', 'churchtools-plugin'),
                        'tone' => self::lastSyncTone(SyncEngine::getLastError() !== null, $facts['last_sync']),
                    ],
                    [
                        'icon' => 'clock',
                        'value' => $facts['next_sync_label'],
                        'label' => sprintf(
                            /* translators: %s: configured sync recurrence, e.g. "Stündlich" */
                            __('Nächste Synchronisation (%s)', 'churchtools-plugin'),
                            self::syncIntervalLabels()[$settings['sync_interval']] ?? $settings['sync_interval']
                        ),
                        'tone' => $facts['next_sync_scheduled'] ? '' : 'error',
                    ],
                    [
                        'icon' => 'list-view',
                        'value' => (string) (new EventRepository())->count(),
                        'label' => __('Gespeicherte Termine', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'calendar-alt',
                        'value' => self::dayCountLabel((int) $settings['sync_days_ahead']),
                        'label' => __('Sync-Zeitraum', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'backup',
                        'value' => (int) $settings['retention_days'] === 0
                            ? __('sofort löschen', 'churchtools-plugin')
                            : self::dayCountLabel((int) $settings['retention_days']),
                        'label' => __('Aufbewahrung nach Event-Ende', 'churchtools-plugin'),
                    ],
                ]);

                return;
            case 'updates':
                $update = UpdatesTab::updateStatus();
                $hasUpdate = $update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>');

                self::renderStatStrip([
                    [
                        'icon' => 'admin-plugins',
                        'value' => CTP_VERSION,
                        'label' => __('Installierte Version', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'cloud-upload',
                        'value' => $hasUpdate ? (string) $update['version'] : __('aktuell', 'churchtools-plugin'),
                        'label' => __('Verfügbare Version', 'churchtools-plugin'),
                        'tone' => $hasUpdate ? 'warn' : 'ok',
                    ],
                    [
                        'icon' => 'clock',
                        'value' => $update['checked'] > 0
                            ? (string) wp_date($facts['date_format'], $update['checked'])
                            : __('noch nie', 'churchtools-plugin'),
                        'label' => __('Letzte Update-Prüfung', 'churchtools-plugin'),
                    ],
                    [
                        'icon' => 'randomize',
                        'value' => __('GitHub Releases', 'churchtools-plugin'),
                        'label' => __('Bezugsquelle', 'churchtools-plugin'),
                    ],
                ]);

                return;
            case 'design':
                self::renderStatStrip(self::statusCardsDesign($settings));

                return;
            case 'group_list':
                self::renderStatStrip(GroupsTab::listCards());

                return;
            case 'groups':
                self::renderStatStrip(GroupsTab::selectionCards());

                return;
            case 'group_sync':
                self::renderStatStrip(GroupsTab::syncCards());

                return;
            case 'post_list':
                self::renderStatStrip(PostsTab::listCards());

                return;
            case 'post_sync':
                self::renderStatStrip(PostsTab::syncCards());

                return;
        }
    }

    /**
     * Die fuenf Entscheidungen, die auf dem Design-Tab gelten, als Statuszeile -
     * in derselben Form wie auf jedem anderen Tab.
     *
     * Sie beantwortet die Frage, die man beim Aufschlagen des Tabs hat: was
     * ist hier gerade eingestellt? Vorher stand die Antwort verstreut in vier
     * Bedienelementen, von denen drei erst nach dem Scrollen an den Fuss der
     * Seite sichtbar waren.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string, swatch?: string}>
     */
    private static function statusCardsDesign(array $settings): array
    {
        $clickLabels = [
            'none' => __('Keine', 'churchtools-plugin'),
            'popup' => __('Popup', 'churchtools-plugin'),
            'page' => __('Eigene Seite', 'churchtools-plugin'),
        ];
        $cornerLabels = [
            'rounded' => __('Rund', 'churchtools-plugin'),
            'square' => __('Eckig', 'churchtools-plugin'),
        ];
        $ratioLabels = [
            'wide' => __('Breit · 16:9', 'churchtools-plugin'),
            'square' => __('Quadratisch · 1:1', 'churchtools-plugin'),
            'tall' => __('Hoch · 4:5', 'churchtools-plugin'),
        ];
        $accentEnabled = !empty($settings['accent_color_enabled']);
        $buttonEnabled = !empty($settings['button_color_enabled']);

        return [
            [
                'icon' => 'admin-links',
                'value' => $clickLabels[$settings['click_behavior']] ?? $settings['click_behavior'],
                'label' => __('Klickverhalten', 'churchtools-plugin'),
            ],
            [
                'icon' => 'grid-view',
                'value' => $cornerLabels[$settings['corner_style']] ?? $settings['corner_style'],
                'label' => __('Ecken', 'churchtools-plugin'),
            ],
            [
                // Farbpunkt statt Dashicon, sobald eine eigene Farbe gesetzt
                // ist - ein Hex-Code allein sagt niemandem, welche Farbe das
                // ist. Ohne eigene Farbe gibt es nichts zu zeigen, dann bleibt
                // es beim Symbol.
                'icon' => 'art',
                'swatch' => $accentEnabled ? (string) $settings['accent_color'] : '',
                'value' => $accentEnabled
                    ? (string) $settings['accent_color']
                    : __('vom Theme', 'churchtools-plugin'),
                'label' => __('Akzentfarbe', 'churchtools-plugin'),
            ],
            [
                // Ohne eigene Buttonfarbe steht hier nicht „vom Theme“ wie bei
                // der Akzentfarbe: die Buttons erben in dem Fall keine
                // Theme-Farbe, sie bleiben hell mit dünnem Rand und werden im
                // gefüllten Zustand schwarz (siehe DesignTab::renderButtonColorField()).
                'icon' => 'button',
                'swatch' => $buttonEnabled ? (string) $settings['button_color'] : '',
                'value' => $buttonEnabled
                    ? (string) $settings['button_color']
                    : __('Standard', 'churchtools-plugin'),
                'label' => __('Buttonfarbe', 'churchtools-plugin'),
            ],
            [
                'icon' => 'format-image',
                'value' => $ratioLabels[$settings['media_aspect_ratio']] ?? $settings['media_aspect_ratio'],
                'label' => __('Bild-Seitenverhältnis', 'churchtools-plugin'),
            ],
            [
                // Das eingestellte Wort selbst statt „An"/„Aus": Es ist die
                // ganze Einstellung, und in Anfuehrungszeichen ist sofort
                // erkennbar, dass es woertlich so auf der Kachel steht.
                'icon' => 'clock',
                'value' => trim((string) $settings['live_label']) !== ''
                    ? sprintf('„%s“', (string) $settings['live_label'])
                    : __('Aus', 'churchtools-plugin'),
                'label' => __('Laufende Termine', 'churchtools-plugin'),
            ],
        ];
    }

    /**
     * Die Kacheln der Uebersicht: was fuer Termine *und* Gruppen gilt, dazu
     * je eine Zahl fuer beide. Bis 2026-09-15 standen hier Kalender, letzter
     * und naechster Sync - also nur die Termine, waehrend das Panel „Gruppen"
     * darunter dieselben Angaben als Tabelle trug. Jetzt tragen beide Panels
     * ihre Sync-Angaben in derselben Tabelle (siehe overviewRows()).
     *
     * @param array<string, mixed> $facts
     *
     * @return array<int, array{icon: string, value: string, label: string, tone?: string}>
     */
    private static function statusCardsOverview(array $facts): array
    {
        $settings = $facts['settings'];

        $cards = [
            [
                'icon' => 'admin-links',
                'value' => $settings['instance'] !== '' ? $settings['instance'] : '—',
                'label' => __('Instanz', 'churchtools-plugin'),
                'tone' => $facts['configured'] ? 'ok' : 'warn',
            ],
            self::apiKeyCard($facts),
            [
                'icon' => 'calendar-alt',
                'value' => (string) (new EventRepository())->count(),
                'label' => __('Gespeicherte Termine', 'churchtools-plugin'),
            ],
        ];

        // Ohne aktive Homepage keine Gruppen-Kachel: Die meisten
        // Installationen zeigen keine Gruppen, eine dauerhafte 0 waere Rauschen.
        if (GroupSettings::enabledHomepages() !== []) {
            $cards[] = [
                'icon' => 'groups',
                'value' => (string) GroupsTab::storedGroupCount(),
                'label' => __('Gespeicherte Gruppen', 'churchtools-plugin'),
            ];
        }

        // Dieselbe Regel fuer Beitraege: nur, wenn der Abgleich an ist.
        if (PostSettings::isEnabled()) {
            $cards[] = [
                'icon' => 'megaphone',
                'value' => (string) PostsTab::storedPostCount(),
                'label' => __('Gespeicherte Beiträge', 'churchtools-plugin'),
            ];
        }

        return $cards;
    }

    /**
     * Die Kachel „API-Key" - auf der Uebersicht und im Reiter „Verbindung".
     *
     * @param array<string, mixed> $facts
     *
     * @return array{icon: string, value: string, label: string, tone: string}
     */
    private static function apiKeyCard(array $facts): array
    {
        return [
            'icon' => 'lock',
            'value' => $facts['api_key_broken']
                ? __('nicht lesbar', 'churchtools-plugin')
                : (ApiKey::isFromConfig()
                    ? __('aus Konfiguration', 'churchtools-plugin')
                    : (ApiKey::isConfigured()
                        ? __('hinterlegt', 'churchtools-plugin')
                        : __('fehlt', 'churchtools-plugin'))),
            'label' => __('API-Key', 'churchtools-plugin'),
            'tone' => $facts['api_key_broken']
                ? 'error'
                : (ApiKey::isConfigured() ? 'ok' : 'warn'),
        ];
    }

    /**
     * Die Zeilen der Tabelle in einem Panel der Uebersicht, fuer Termine und
     * Gruppen in derselben Reihenfolge: Auswahl, Bestand, letzter und
     * naechster Lauf.
     *
     * @param array<int, array{label: string, value: string}> $rows
     */
    public static function renderOverviewRows(array $rows): void
    {
        ?>
        <table class="widefat striped ctp-borderless ctp-keyvalue-table">
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <th><?php echo esc_html($row['label']); ?></th>
                        <td><?php echo esc_html($row['value']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Die Linkzeile unter einem Panel der Uebersicht - fuer Termine und
     * Gruppen dieselben Ziele in derselben Reihenfolge.
     *
     * @param array<int, array{url: string, icon: string, label: string}> $links
     */
    public static function renderQuicklinks(array $links): void
    {
        ?>
        <p class="ctp-quicklinks">
            <?php foreach ($links as $link) : ?>
                <a href="<?php echo esc_url($link['url']); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($link['icon']); ?>" aria-hidden="true"></span>
                    <?php echo esc_html($link['label']); ?>
                </a>
            <?php endforeach; ?>
        </p>
        <?php
    }

    /**
     * Die Farbe der Kachel „Letzte Synchronisation" - fuer Termine und Gruppen
     * nach derselben Regel: rot nach einem Fehler, gruen nach einem Erfolg,
     * neutral, solange noch nie etwas lief.
     */
    public static function lastSyncTone(bool $failed, string $lastSync): string
    {
        if ($failed) {
            return 'error';
        }

        return $lastSync !== '' ? 'ok' : '';
    }

    /**
     * „1 Tag“ statt „1 Tage“, ohne die Plural-Funktion von WordPress: der
     * Extraktor in bin/make-pot.php kennt nur __()/esc_html__() und bricht bei
     * Plural- oder Kontextaufrufen ausdruecklich ab (siehe seine eigene
     * Fehlermeldung). Deutsch braucht genau diese eine Unterscheidung, also
     * kostet der Verzicht hier nichts.
     */
    private static function dayCountLabel(int $days): string
    {
        if ($days === 1) {
            return __('1 Tag', 'churchtools-plugin');
        }

        return sprintf(
            /* translators: %d: number of days */
            __('%d Tage', 'churchtools-plugin'),
            $days
        );
    }

    /**
     * Aktionsleiste unter einer Panel-Ueberschrift: genau ein Knopf plus die
     * Statuszeile, in der seine AJAX-Antwort landet.
     *
     * Vorher stand jeder dieser Knoepfe woanders - „Jetzt synchronisieren“ ganz
     * unten im Panel der Uebersicht, „Kalender laden“ oben in einer
     * Formularzelle, und die Rueckmeldung war jedes Mal ein nackter
     * <span> ohne erkennbaren Erfolgs- oder Fehlerzustand. Jetzt sitzt die
     * Leiste ueberall an derselben Stelle (direkt unter der Ueberschrift, ueber
     * dem Inhalt, den sie veraendert) und die Rueckmeldung ist ueberall
     * dasselbe Bauteil (.ctp-inline-status, siehe ctpSetStatus() in
     * renderPage()).
     */
    public static function renderActionBar(string $buttonId, string $label, string $hint = ''): void
    {
        ?>
        <div class="ctp-toolbar">
            <button type="button" class="button button-primary" id="<?php echo esc_attr($buttonId); ?>">
                <?php echo esc_html($label); ?>
            </button>
            <span class="ctp-inline-status" id="<?php echo esc_attr($buttonId); ?>-result" role="status" aria-live="polite"></span>
            <?php if ($hint !== '') : ?>
                <span class="ctp-toolbar__hint"><?php echo esc_html($hint); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Der Hinweis auf Bilder, die der letzte Lauf nicht uebernehmen konnte -
     * fuer Termine und Gruppen dieselbe Form, gezeigt auf der Uebersicht und
     * dort, wo der jeweilige Knopf „synchronisieren" steht.
     *
     * Gelb und nicht rot, und neben dem Sync-Fehler statt an seiner Stelle:
     * Der Lauf selbst ist gelungen (siehe Sync\ImageImportFailures). Bis
     * 1.32.1 blieb ein gescheiterter Import ganz still, und ein 401 beim
     * Bilddownload fiel zwei Wochen lang niemandem auf.
     *
     * Zahl-neutral formuliert, weil bin/make-pot.php keine Plurale kann.
     *
     * @param array{time: string, count: int, reasons: string}|null $warning
     * @param 'events'|'groups'|'posts'                            $subject
     */
    public static function renderImageWarning(?array $warning, string $subject): void
    {
        if ($warning === null) {
            return;
        }

        if ($subject === 'groups') {
            /* translators: 1: date/time of the group sync, 2: number of groups whose image failed, 3: reasons with counts */
            $format = __('Beim letzten Gruppen-Sync (%1$s) ließen sich nicht alle Gruppenbilder übernehmen – betroffene Gruppen: %2$d. Grund: %3$s. Die Gruppen selbst sind aktuell; jeder weitere Sync versucht es erneut.', 'churchtools-plugin');
        } elseif ($subject === 'posts') {
            /* translators: 1: date/time of the post sync, 2: number of post images that failed, 3: reasons with counts */
            $format = __('Beim letzten Beitrags-Sync (%1$s) ließen sich nicht alle Bilder übernehmen – betroffene Bilder: %2$d. Grund: %3$s. Die Beiträge selbst sind aktuell; jeder weitere Sync versucht es erneut.', 'churchtools-plugin');
        } else {
            /* translators: 1: date/time of the sync, 2: number of series whose image failed, 3: reasons with counts */
            $format = __('Beim letzten Sync (%1$s) ließen sich nicht alle Terminbilder übernehmen – betroffene Serien: %2$d. Grund: %3$s. Die Termine selbst sind aktuell; jeder weitere Sync versucht es erneut.', 'churchtools-plugin');
        }
        ?>
        <div class="notice notice-warning inline">
            <p>
                <?php
                echo esc_html(sprintf(
                    $format,
                    mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $warning['time']),
                    $warning['count'],
                    wp_html_excerpt($warning['reasons'], 600, '…')
                ));
                ?>
            </p>
        </div>
        <?php
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = self::currentTab();
        $icons = self::tabIcons();
        ?>
        <div class="wrap ctp-admin">
            <?php
            $area = self::currentArea();
            $areaHeader = self::areaHeader($area);
            ?>
            <div class="ctp-admin-header">
                <span class="ctp-admin-logo" aria-hidden="true">
                    <span class="dashicons dashicons-<?php echo esc_attr($areaHeader['icon']); ?>"></span>
                </span>
                <h1><?php echo esc_html(self::areas()[$area]); ?></h1>
            </div>
            <p class="ctp-admin-tagline">
                <?php echo esc_html($areaHeader['tagline']); ?>
            </p>
            <?php
            /*
             * Die Reiter sind klassische WordPress-Buttons - `button`, der
             * aktive `button-primary` - in einem Raster, das die Reihe bis zur
             * Kante der Kacheln darunter fuellt (siehe .ctp-tabs in admin.css).
             * Bis 2026-09-11 waren es WordPress-Reiter (`nav-tab`) auf einer
             * durchgehenden Linie. Die Linie wollte der Nutzer nicht mehr („als
             * Trenner hier keine Linien"), und ohne sie liest sich ein Reiter
             * nicht mehr als Reiter, sondern als Knopf - also sind es jetzt
             * Knoepfe, und zwar die, die WordPress ueberall sonst auch zeigt
             * („klassische Buttons ohne die neuen Styles").
             *
             * `aria-current` sagt, welcher Bereich offen ist - vorher stand das
             * nur in einer Farbe, die ein Screenreader nicht sieht. Dieselbe
             * Auszeichnung tragen die Unter-Reiter des Design-Tabs.
             *
             * Seit 2026-09-14 nur die Reiter des Bereichs, in dem man steht -
             * die Bereiche selbst stehen links im WordPress-Menue (siehe
             * areas()). Ein Bereich mit einem einzigen Reiter bekommt keine
             * Reihe: ein Knopf, der nur auf die Seite zeigt, auf der man ist,
             * waere keine Navigation.
             */
            $areaTabs = self::AREA_TABS[self::currentArea()];
            $tabLabels = self::tabs();
            ?>
            <?php if (count($areaTabs) > 1) : ?>
            <nav class="ctp-tabnav" aria-label="<?php esc_attr_e('Bereiche', 'churchtools-plugin'); ?>">
                <div class="ctp-tabs" style="--ctp-tab-count:<?php echo (int) count($areaTabs); ?>">
                    <?php foreach ($areaTabs as $tabSlug) : ?>
                        <a href="<?php echo esc_url(self::tabUrl($tabSlug)); ?>"
                            class="button <?php echo $tab === $tabSlug ? 'button-primary' : ''; ?>"
                            <?php echo $tab === $tabSlug ? 'aria-current="page"' : ''; ?>>
                            <span class="dashicons dashicons-<?php echo esc_attr($icons[$tabSlug] ?? 'admin-generic'); ?>" aria-hidden="true"></span>
                            <?php echo esc_html($tabLabels[$tabSlug]); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>
            <?php endif; ?>

            <?php
            /*
             * Die Statuszeile steht auf jedem Tab, an dem Daten aus ChurchTools
             * hereinkommen, an genau derselben Stelle: zwischen Tab-Navigation
             * und Inhalt. renderTabStatus() entscheidet, welche Kacheln der
             * jeweilige Tab braucht.
             *
             * Der Tab „Events“ ist ausgenommen, weil er seine Zeile aus den
             * Zahlen baut, die er fuer die Tabelle ohnehin schon geladen hat
             * (siehe EventsTab::render()) - und der Detailblick auf einen
             * einzelnen Termin bekommt gar keine: dort geht es um eine Zeile,
             * nicht um den Bestand.
             */
            if ($tab !== 'events') {
                self::renderTabStatus($tab);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation (which event to display), not a state change; same pattern as currentTab()'s $_GET['tab'] read above.
            $eventId = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
            ?>

            <?php if ($tab === 'status') : ?>
                <?php OverviewTab::render(); ?>
            <?php elseif ($tab === 'embed') : ?>
                <?php EmbedTab::render(); ?>
            <?php elseif ($tab === 'events') : ?>
                <?php
                if ($eventId > 0) {
                    EventsTab::renderEventDetail($eventId);
                } else {
                    EventsTab::render();
                }
                ?>
            <?php elseif ($tab === 'calendars') : ?>
                <?php CalendarsTab::render(); ?>
            <?php elseif ($tab === 'rooms') : ?>
                <?php RoomsTab::render(); ?>
            <?php elseif ($tab === 'group_list') : ?>
                <?php GroupsTab::renderList(); ?>
            <?php elseif ($tab === 'groups') : ?>
                <?php GroupsTab::render(); ?>
            <?php elseif ($tab === 'group_sync') : ?>
                <?php GroupsTab::renderSync(); ?>
            <?php elseif ($tab === 'group_embed') : ?>
                <?php GroupsTab::renderEmbed(); ?>
            <?php elseif ($tab === 'post_list') : ?>
                <?php PostsTab::renderList(); ?>
            <?php elseif ($tab === 'post_sync') : ?>
                <?php PostsTab::renderSync(); ?>
            <?php elseif ($tab === 'post_embed') : ?>
                <?php PostsTab::renderEmbed(); ?>
            <?php elseif ($tab === 'updates') : ?>
                <?php UpdatesTab::render(); ?>
            <?php elseif ($tab === 'log') : ?>
                <?php LogTab::render(); ?>
            <?php elseif ($tab === 'design') : ?>
                <?php DesignTab::render(); ?>
            <?php else : ?>
                <form method="post" action="options.php" class="ctp-settings-form">
                    <div class="ctp-panel">
                        <?php
                        settings_fields(self::PAGE_SLUG);
                        do_settings_sections(self::PAGE_SLUG . '_' . $tab);
                        ?>
                    </div>
                    <?php $this->renderSaveBar(); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajaxTestConnection(): void
    {
        check_ajax_referer('ctp_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $connection = self::effectiveConnection();

        if ($connection['error'] !== '') {
            wp_send_json_error(['message' => $connection['error']]);
        }

        try {
            $client = new Client($connection['base_url'], $connection['api_key']);
            $person = $client->whoami();
            $name = trim(($person['firstName'] ?? '') . ' ' . ($person['lastName'] ?? ''));

            wp_send_json_success([
                'message' => $name !== ''
                    /* translators: %s: full name of the authenticated ChurchTools person */
                    ? sprintf(__('Verbunden als %s', 'churchtools-plugin'), $name)
                    : __('Verbindung erfolgreich', 'churchtools-plugin'),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public function ajaxFetchResources(): void
    {
        check_ajax_referer('ctp_fetch_resources', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $connection = self::effectiveConnection();

        if ($connection['error'] !== '') {
            wp_send_json_error(['message' => $connection['error']]);
        }

        try {
            $result = ResourceList::refresh(new Client($connection['base_url'], $connection['api_key']));
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }

        // Wie bei den Kalendern: Der Erfolgszweig laedt die Seite neu, eine
        // Meldung waere dort nicht zu lesen. Die stehengebliebene Liste ist
        // deshalb ein Fehler fuer die Anzeige - sonst haette der Knopf eben
        // „geladen" gemeldet und nichts getan.
        if ($result['status'] === 'empty') {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success(['count' => $result['count']]);
    }

    public function ajaxFetchCalendars(): void
    {
        check_ajax_referer('ctp_fetch_calendars', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $connection = self::effectiveConnection();

        if ($connection['error'] !== '') {
            wp_send_json_error(['message' => $connection['error']]);
        }

        try {
            $result = CalendarList::refresh(new Client($connection['base_url'], $connection['api_key']));
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }

        if ($result['status'] === 'empty') {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success(['count' => $result['count']]);
    }

    /**
     * Zwingt WordPress zu einer frischen Update-Pruefung.
     *
     * Ohne diesen Knopf haengt der Tab „Updates“ am Zwischenspeicher von
     * WordPress (`update_plugins`, ueblicherweise zwoelf Stunden alt) - wer
     * gerade ein Release veroeffentlicht hat und nachsehen will, ob es
     * ankommt, konnte nur warten. Gefragt wird dabei genau eine Quelle - die
     * Metadatendatei dieses Plugins - und nicht mehr ueber
     * wp_update_plugins() der Update-Dienst von WordPress nach saemtlichen
     * installierten Plugins (siehe GitHubUpdateChecker::checkNow()).
     */
    public function ajaxCheckUpdates(): void
    {
        check_ajax_referer('ctp_check_updates', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $update = GitHubUpdateChecker::checkNow();

        if ($update === null) {
            wp_send_json_error(['message' => __('Die Update-Prüfung steht auf dieser Installation nicht zur Verfügung.', 'churchtools-plugin')]);
        }

        if ($update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>')) {
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: newer version number available via GitHub */
                    __('Version %s steht bereit.', 'churchtools-plugin'),
                    $update['version']
                ),
            ]);
        }

        wp_send_json_success(['message' => __('Das Plugin ist auf dem aktuellen Stand.', 'churchtools-plugin')]);
    }

    public function ajaxRunSync(): void
    {
        check_ajax_referer('ctp_run_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'churchtools-plugin')], 403);
        }

        $settings = Settings::get();
        $calendarIds = Settings::getEnabledCalendarIds();

        if ($settings['instance'] === '' || !ApiKey::isUsable()) {
            wp_send_json_error(['message' => $settings['instance'] === '' ? __('Bitte zuerst die Verbindung zu ChurchTools einrichten.', 'churchtools-plugin') : ApiKey::unusableMessage()]);
        }

        // Ohne aktiven Kalender ist dieser Lauf kein Abgleich mehr, sondern ein
        // Aufraeumen (siehe SyncEngine::run()) - und genau dafuer ist diese
        // Schaltflaeche die Bedienung. Vorher stand hier eine Fehlermeldung,
        // wodurch die Termine des zuletzt abgewaehlten Kalenders bis zum
        // naechsten planmaessigen Lauf im Tab "Events" stehen blieben, ohne
        // dass sich daran etwas machen liess.
        if ($calendarIds === []) {
            if (!SyncEngine::run()) {
                wp_send_json_error(['message' => RunLock::busyMessage()]);
            }

            // Auch das Aufraeumen meldet einen Fehler nicht mehr durch eine
            // Ausnahme, sondern ueber die Option (siehe unten).
            $cleanUpError = SyncEngine::getLastError();

            if ($cleanUpError !== null) {
                wp_send_json_error(['message' => $cleanUpError['message']]);
            }

            // „Kein Kalender aktiv“ kann auch heissen: Die Liste liess sich
            // gar nicht erst holen - ein abgelaufener API-Key etwa -, und
            // deshalb ist keiner aktiv. Das ist der Unterschied zwischen
            // „nichts zu tun“ und „kommt nicht an ChurchTools heran“, und er
            // stand bisher nur im Tab „Kalender“, waehrend dieser Knopf
            // Erfolg meldete.
            $calendarError = SyncEngine::getLastCalendarError();

            if ($calendarError !== null) {
                wp_send_json_error(['message' => $calendarError['message']]);
            }

            wp_send_json_success([
                'message' => __('Kein Kalender ist aktiv – die gespeicherten Termine wurden entfernt.', 'churchtools-plugin'),
                'count' => (new EventRepository())->count(),
                'location_summary' => RoomsTab::locationSummary(),
                'last_sync' => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) get_option('ctp_last_sync', '')),
            ]);
        }

        // SyncEngine::run() catches its own exceptions and persists them (see its
        // docblock) so an unattended WP-Cron run never fatals — that means a failure
        // here no longer surfaces as a thrown exception, it has to be read back via
        // getLastError() instead.
        if (!SyncEngine::run()) {
            wp_send_json_error(['message' => RunLock::busyMessage()]);
        }

        $lastError = SyncEngine::getLastError();

        if ($lastError !== null) {
            wp_send_json_error(['message' => $lastError['message']]);
        }

        wp_send_json_success([
            'message' => __('Synchronisation abgeschlossen.', 'churchtools-plugin'),
            'count' => (new EventRepository())->count(),
            'location_summary' => RoomsTab::locationSummary(),
            'last_sync' => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) get_option('ctp_last_sync', '')),
        ]);
    }

    /**
     * Der Reiter schickt das Feld immer mit (das Formular traegt die ganze
     * Raumauswahl), ein fehlendes `resources` heisst deshalb „von einem anderen
     * Reiter gespeichert" und laesst die Einstellung unangetastet.
     */
    private static function sanitizeRoomsMode(array $input, array $existing): string
    {
        // Ueber resolveRoomsMode() und nicht direkt aus $existing: Sonst
        // schriebe das Speichern eines *anderen* Reiters die Bruecke aus 1.12.0
        // still auf den Standard um.
        $bestehend = ResourceList::resolveMode($existing);

        if (!array_key_exists('resources', $input)) {
            return $bestehend;
        }

        $gewaehlt = (string) ($input['rooms_mode'] ?? '');

        return in_array($gewaehlt, ResourceList::MODES, true) ? $gewaehlt : $bestehend;
    }
}
