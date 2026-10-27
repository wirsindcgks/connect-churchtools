<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

/**
 * Der Reiter „Updates" unter „Einstellungen", dazu die Auskunft ueber neue
 * Versionen und das Changelog, die auch Statuszeile und Uebersicht lesen.
 *
 * Bis 1.38 Teil von SettingsPage, herausgeloest wie GroupsTab/PostsTab
 * (5.1 im Review-Plan).
 */
final class UpdatesTab
{
    /**
     * Dasselbe Repository, aus dem GitHubUpdateChecker::METADATA_URL seine
     * Angaben zu neuen Versionen liest - hier verlinkt der Tab „Updates“ die
     * Quelle, dort wird sie abgefragt. Wer aus einem Fork verteilt, aendert
     * beide. Der abschliessende Schraegstrich gehoert dazu, die Links haengen
     * ihre Pfade direkt an.
     */
    private const REPO_URL = 'https://github.com/wirsindcgks/connect-churchtools/';

    /**
     * Liest die von WordPress selbst gepflegte Update-Pruefung (befuellt vom
     * GitHubUpdateChecker, siehe includes/Update/) statt bei jedem Aufruf einer
     * Admin-Seite eine frische Anfrage an GitHub zu schicken.
     *
     * @return array{version: ?string, checked: int}
     */
    public static function updateStatus(): array
    {
        $pluginFile = plugin_basename(CTP_PLUGIN_FILE);
        $updatePlugins = get_site_transient('update_plugins');

        return [
            'version' => is_object($updatePlugins) && isset($updatePlugins->response[$pluginFile]->new_version)
                ? (string) $updatePlugins->response[$pluginFile]->new_version
                : null,
            'checked' => is_object($updatePlugins) && isset($updatePlugins->last_checked)
                ? (int) $updatePlugins->last_checked
                : 0,
        ];
    }

    /**
     * Der Tab „Updates“ - seit dem Wegfall des GitHub-Tokens ohne eine einzige
     * Einstellung, dafuer mit der Auskunft, fuer die man ihn tatsaechlich
     * aufsucht: Steht ein Update an, wann wurde zuletzt nachgesehen, und was
     * hat sich in den letzten Versionen geaendert.
     *
     * Der Changelog stand vorher nur als fuenfzeiliger Auszug der *einen*
     * aktuellen Version auf der Uebersicht. Wer nach einem Update wissen
     * wollte, was zwei Versionen zurueck passiert ist, musste dafuer die
     * CHANGELOG.md im Repository aufmachen.
     */
    public static function render(): void
    {
        $update = self::updateStatus();
        $hasUpdate = $update['version'] !== null && version_compare($update['version'], CTP_VERSION, '>');
        $releases = self::changelogReleases();
        ?>
        <div class="ctp-panel">
            <h2><?php esc_html_e('Plugin-Updates über GitHub', 'churchtools-plugin'); ?></h2>
            <?php
            SettingsPage::renderActionBar(
                'ctp-check-updates',
                __('Jetzt auf Updates prüfen', 'churchtools-plugin'),
                __('Verwirft die zwischengespeicherte Update-Prüfung von WordPress und fragt GitHub sofort neu.', 'churchtools-plugin')
            );
            ?>

            <?php if ($hasUpdate) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: newer version number available via GitHub */
                            esc_html__('Version %s steht bereit. Einspielen lässt sie sich über die Plugins-Übersicht von WordPress.', 'churchtools-plugin'),
                            esc_html((string) $update['version'])
                        );
                        ?>
                        <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                            <?php esc_html_e('Zur Plugins-Übersicht', 'churchtools-plugin'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to the plugin's GitHub repository */
                    esc_html__('Dieses Plugin liegt nicht auf WordPress.org, sondern bezieht seine Updates aus den GitHub-Releases von %s. Das Repository ist öffentlich – es ist kein Zugangstoken nötig.', 'churchtools-plugin'),
                    '<a href="' . esc_url(self::REPO_URL) . '" target="_blank" rel="noopener noreferrer">wirsindcgks/connect-churchtools</a>'
                );
                ?>
            </p>

            <p class="ctp-quicklinks">
                <a href="<?php echo esc_url(self::REPO_URL); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-randomize" aria-hidden="true"></span>
                    <?php esc_html_e('Repository auf GitHub', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(self::REPO_URL . 'releases'); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
                    <?php esc_html_e('Alle Releases', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(self::REPO_URL . 'blob/main/CHANGELOG.md'); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-media-text" aria-hidden="true"></span>
                    <?php esc_html_e('Vollständiger Changelog', 'churchtools-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(self::REPO_URL . 'issues'); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-sos" aria-hidden="true"></span>
                    <?php esc_html_e('Problem melden', 'churchtools-plugin'); ?>
                </a>
            </p>
        </div>

        <?php
        /*
         * Der einzige Schalter, der das Plugin als Ganzes betrifft - Termine
         * und Gruppen gleichermassen. Bis 2026-09-15 stand er unter
         * „Events → Synchronisation", wo ihn bei den Gruppen niemand sucht.
         */
        ?>
        <form method="post" action="options.php" class="ctp-settings-form">
            <div class="ctp-panel">
                <?php
                settings_fields(SettingsPage::PAGE_SLUG);
                do_settings_sections(SettingsPage::PAGE_SLUG . '_uninstall');
                ?>
            </div>
            <?php SettingsPage::renderSaveBar(); ?>
        </form>

        <?php if ($releases !== []) : ?>
            <div class="ctp-panel">
                <h2><?php esc_html_e('Änderungen der letzten Versionen', 'churchtools-plugin'); ?></h2>
                <?php foreach ($releases as $release) : ?>
                    <div class="ctp-release">
                        <h3 class="ctp-release__head">
                            <span class="ctp-release__version">
                                <?php echo esc_html($release['version']); ?>
                            </span>
                            <?php if ($release['version'] === CTP_VERSION) : ?>
                                <span class="ctp-release__badge"><?php esc_html_e('installiert', 'churchtools-plugin'); ?></span>
                            <?php endif; ?>
                            <?php if ($release['date'] !== '') : ?>
                                <span class="ctp-release__date"><?php echo esc_html($release['date']); ?></span>
                            <?php endif; ?>
                        </h3>
                        <?php if ($release['items'] === []) : ?>
                            <p class="ctp-empty-state"><?php esc_html_e('Keine Stichpunkte hinterlegt.', 'churchtools-plugin'); ?></p>
                        <?php else : ?>
                            <ul class="ctp-changelog-excerpt ctp-changelog-excerpt--full">
                                <?php foreach ($release['items'] as $item) : ?>
                                    <li>
                                        <strong><?php echo esc_html($item['lead']); ?></strong>
                                        <?php if ($item['text'] !== '') : ?>
                                            <span class="ctp-release__detail"><?php echo esc_html($item['text']); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Liest die obersten Release-Bloecke der CHANGELOG.md (liegt im
     * Release-Zip, siehe .github/release-excludes.txt) und gibt sie als
     * Version, Datum und Stichpunkte zurueck.
     *
     * Kein Markdown-Betrachter, sondern genau so viel Auswertung, wie die
     * beiden Ansichten brauchen: die Uebersicht zeigt den obersten Block als
     * kurze Liste, der Tab „Updates“ die letzten drei Versionen mit
     * Ueberschrift. Erwartete Ueberschriftform ist die von Keep a Changelog,
     * die diese Datei durchgehend benutzt: `## [0.9.2] - 2026-08-18`.
     * Die `### Added`/`### Changed`-Zwischenueberschriften werden bewusst
     * uebergangen - im Backend interessiert, *was* sich geaendert hat, nicht
     * in welche Kategorie es faellt.
     *
     * @return array<int, array{version: string, date: string, items: array<int, array{lead: string, text: string}>}>
     */
    public static function changelogReleases(int $maxReleases = 3, int $maxItems = 6): array
    {
        $path = CTP_PLUGIN_DIR . 'CHANGELOG.md';
        if (!is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $releases = [];
        $current = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                if ($current !== null) {
                    $releases[] = $current;

                    if (count($releases) >= $maxReleases) {
                        return $releases;
                    }
                }

                preg_match('/^## \[?([^\]\s]+)\]?(?:\s*[-–]\s*(.+))?$/u', $line, $matches);

                // Keep a Changelog sammelt Kommendes unter [Unreleased]. Das
                // ist keine Version - in einem Stand von main stuende sonst
                // „Unreleased“ als neueste Version in der Uebersicht.
                if (strcasecmp(trim($matches[1] ?? ''), 'Unreleased') === 0) {
                    $current = null;

                    continue;
                }

                $current = [
                    'version' => trim($matches[1] ?? ''),
                    'date' => trim($matches[2] ?? ''),
                    'items' => [],
                ];

                continue;
            }

            if ($current !== null && str_starts_with($line, '- ') && count($current['items']) < $maxItems) {
                $current['items'][] = self::splitChangelogLine(substr($line, 2));
            }
        }

        if ($current !== null) {
            $releases[] = $current;
        }

        return $releases;
    }

    /**
     * Zerlegt eine Changelog-Zeile in Kurzfassung und Erklaerung.
     *
     * Jeder Eintrag in der CHANGELOG.md ist nach demselben Muster gebaut:
     * ein fett gesetzter Satz, der sagt was sich geaendert hat, dann der
     * Absatz, der sagt warum. Genau an dieser Naht wird hier getrennt - die
     * Uebersicht zeigt nur die Kurzfassung, der Tab „Updates“ beides.
     *
     * Vorher lief beides als eine Zeichenkette durch: die Sternchen und
     * Backticks standen roh im Text, und weil ein einzelner Eintrag einen
     * ganzen Absatz lang sein kann, musste hart nach 160 Zeichen abgeschnitten
     * werden - womit auf beiden Seiten die Haelfte fehlte.
     *
     * Zwei Schreibweisen kommen in der Datei vor, der Punkt innerhalb der
     * Fettung (`**… sagt es.**`) und der Doppelpunkt danach
     * (`**… funktionslos**:`); die Trennstelle liegt in beiden Faellen an den
     * schliessenden Sternchen.
     *
     * @return array{lead: string, text: string}
     */
    private static function splitChangelogLine(string $line): array
    {
        // Backticks weg: Markdown-Code-Auszeichnung, die im Backend nichts
        // auszeichnet, sondern nur als Zeichen dasteht.
        $line = trim((string) preg_replace('/`(.+?)`/u', '$1', $line));

        if (preg_match('/^\*\*(.+?)\*\*(.*)$/us', $line, $matches) !== 1) {
            // Auch hier Fettungen entfernen, nicht nur im Zweig darunter: Eine
            // Zeile, die *nicht* mit einem fetten Satz beginnt, kann trotzdem
            // eine mittendrin haben - und die stand vorher als "**" im Backend.
            $line = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $line);

            return ['lead' => mb_strimwidth($line, 0, 200, '…'), 'text' => ''];
        }

        $lead = trim($matches[1]);
        // Fuehrende Satzzeichen des Uebergangs (":" oder ",") gehoeren zur
        // Naht, nicht zum Folgesatz.
        $text = trim((string) preg_replace('/^[\s:,–-]+/u', '', $matches[2]));
        // Weitere Fettungen mitten im Text tragen im Backend nichts bei.
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);

        // Der fette Satz endet in der Datei mal mit Punkt, mal ohne - hier
        // immer mit, damit die Kurzfassungen untereinander gleich aussehen.
        if ($lead !== '' && !preg_match('/[.!?:]$/u', $lead)) {
            $lead .= '.';
        }

        return ['lead' => $lead, 'text' => $text];
    }
}
