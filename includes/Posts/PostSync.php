<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Posts;

use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Log;
use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Settings;
use ChurchToolsPlugin\Sync\ImageImportFailures;
use ChurchToolsPlugin\Sync\RunLock;
use ChurchToolsPlugin\Sync\SyncEngine;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Uebernimmt die Beitraege oeffentlicher Gruppen als Kopie nach WordPress -
 * Neuigkeiten fuer die Website, in der Optik der Gruppenkacheln (Anstoss:
 * Feature-Wunsch in Issue #3, 2026-09-30).
 *
 * Zwei Bedingungen, beide vom Nutzer gesetzt (2026-10-07): Abgefragt wird
 * nur, wenn der Schalter im Bereich „Beiträge" an ist (PostSettings), und
 * uebernommen werden nur Beitraege, die ChurchTools selbst als oeffentlich
 * fuehrt - eine Gruppe mit Sichtbarkeit „öffentlich" *und* ein Beitrag
 * „sichtbar für alle, die die Gruppe sehen". Der API-Key sieht mehr als ein
 * Besucher; die Pruefung steht deshalb zweimal, als Filter im Abruf
 * (Client::getPublicPosts()) und an jedem Beitrag (normalizePost()).
 *
 * Was uebernommen wird, ist eine benannte Liste von Feldern: Titel, Text,
 * Datum, Ablaufdatum, Gruppe, Bilder. Verfasser (`actor`), Kommentare und
 * Reaktionen bleiben draussen - sie nennen Personen, und Personen gehoeren
 * nicht auf die Website (siehe plan.md, Grillrunde 2026-09-01).
 *
 * Gespeichert wird wie bei den Gruppen in Optionen ohne Autoload: hoechstens
 * MAX_POSTS Beitraege, kein Zeitfenster und kein Paging auf der Website.
 */
final class PostSync
{
    public const HOOK = 'ctp_run_post_sync';

    /** [ 'fetched' => mysql, 'empty_runs' => int, 'posts' => list<array> ] */
    public const DATA_OPTION = 'ctp_posts';

    /**
     * Nach Bildadresse: Attachment-ID. Nach Adresse statt nach Beitrag, weil
     * ein Beitrag mehrere Bilder traegt und dasselbe Bild in zwei Beitraegen
     * stehen kann - es soll dann einmal in der Mediathek liegen.
     */
    public const IMAGES_OPTION = 'ctp_post_images';

    public const ERROR_OPTION = 'ctp_post_sync_error';

    public const IMAGE_WARNING_OPTION = 'ctp_post_image_warning';

    public const LAST_SYNC_OPTION = 'ctp_post_last_sync';

    /**
     * Eigener Merker, aus demselben Grund wie bei den Gruppen: Der Termin-Sync
     * haelt jedes Bild mit '_ctp_source_image_url' ohne Terminzeile fuer
     * verwaist (siehe SyncEngine::importImage()).
     */
    public const IMAGE_META_KEY = '_ctp_post_source_image_url';

    /** Name der Sperre dieses Abgleichs, siehe Sync\RunLock. */
    public const LOCK = 'posts';

    /**
     * Wie viele Beitraege vorgehalten werden. Eine Website zeigt die
     * neuesten; mehr als eine Handvoll Seiten News liest niemand, und jeder
     * Beitrag kann Bilder in die Mediathek bringen.
     */
    public const MAX_POSTS = 30;

    /**
     * Bilder je Beitrag. ChurchTools erlaubt mehrere (an der Referenzinstanz
     * zwei Seiten eines Flyers); die Kachel zeigt das erste, das Popup alle.
     */
    public const IMAGES_PER_POST = 4;

    /**
     * Wie viele Seiten hoechstens abgefragt werden. Die Spec nennt fuer
     * `limit` keine Obergrenze; sollte die Instanz trotzdem weniger liefern
     * als verlangt, fuehrt der Cursor weiter - aber nicht endlos.
     */
    private const MAX_PAGES = 5;

    /** Siehe GroupSync::EMPTY_RUNS_BEFORE_CLEAR. */
    private const EMPTY_RUNS_BEFORE_CLEAR = 3;

    public static function registerHooks(): void
    {
        add_action(self::HOOK, [self::class, 'runScheduled']);
    }

    /** Fuer WP-Cron: Eine Action gibt nichts zurueck, run() sagt, ob es lief. */
    public static function runScheduled(): void
    {
        self::run();
    }

    /**
     * @return bool false, wenn gerade ein anderer Beitrags-Lauf die Sperre hielt
     */
    public static function run(): bool
    {
        return RunLock::run(self::LOCK, static function (): void {
            self::runUnlocked();
        });
    }

    private static function runUnlocked(): void
    {
        // Abgeschaltet heisst: nichts fragen und nichts zeigen. Der Lauf nach
        // dem Abschalten (Installer::onPostSettingsUpdated()) raeumt Beitraege
        // und Bilder ab - wie das Abwaehlen der letzten Gruppen-Homepage.
        if (!PostSettings::isEnabled()) {
            self::clear();

            return;
        }

        $startedAt = microtime(true);
        $baseUrl = Settings::getBaseUrl();

        if ($baseUrl === '') {
            return;
        }

        if (!ApiKey::isUsable()) {
            update_option(self::ERROR_OPTION, [
                'time' => current_time('mysql'),
                'message' => ApiKey::unusableMessage(),
            ]);
            Log::error(Log::AREA_POSTS, 'Synchronisation der Beiträge fehlgeschlagen: ' . ApiKey::unusableMessage());

            return;
        }

        $now = current_time('mysql');

        try {
            $posts = self::fetchPosts(new Client($baseUrl, ApiKey::current()), $baseUrl, current_datetime()->getTimestamp());
        } catch (Throwable $exception) {
            // Der Bestand bleibt, bis ein Abruf gelingt.
            update_option(self::ERROR_OPTION, ['time' => $now, 'message' => $exception->getMessage()]);
            Log::error(Log::AREA_POSTS, 'Synchronisation der Beiträge fehlgeschlagen: ' . $exception->getMessage());

            return;
        }

        $data = self::nextData(self::storedData(), $posts, $now);
        update_option(self::DATA_OPTION, $data, false);

        $imageFailures = new ImageImportFailures();
        self::syncImages($data['posts'], $imageFailures);
        $imageFailures->store(self::IMAGE_WARNING_OPTION, $now);

        Log::info(Log::AREA_POSTS, sprintf(
            'Synchronisation abgeschlossen: %d Beiträge, %d Bilder gescheitert (%.1f s).',
            count($data['posts']),
            $imageFailures->count(),
            microtime(true) - $startedAt
        ));

        delete_option(self::ERROR_OPTION);
        update_option(self::LAST_SYNC_OPTION, $now);
    }

    /**
     * Holt die neuesten oeffentlichen Beitraege, bis MAX_POSTS beisammen sind
     * oder ChurchTools keine weiteren liefert. Der Cursor kommt aus dem
     * letzten Eintrag der *Antwort*, nicht der uebernommenen Beitraege - ein
     * herausgefilterter Beitrag am Seitenende soll nicht zweimal abgefragt
     * werden.
     *
     * @return list<array>
     */
    public static function fetchPosts(Client $client, string $baseUrl, int $nowTimestamp): array
    {
        $posts = [];
        $seen = [];
        $before = null;
        $lastGuid = null;

        for ($page = 0; $page < self::MAX_PAGES && count($posts) < self::MAX_POSTS; $page++) {
            $response = $client->getPublicPosts(self::MAX_POSTS, $before, $lastGuid);

            if ($response === []) {
                break;
            }

            foreach ($response as $post) {
                $normalized = is_array($post) ? self::normalizePost($post, $baseUrl, $nowTimestamp) : null;

                if ($normalized !== null && !isset($seen[$normalized['id']]) && count($posts) < self::MAX_POSTS) {
                    $seen[$normalized['id']] = true;
                    $posts[] = $normalized;
                }
            }

            $last = end($response);
            $nextBefore = is_array($last) && is_string($last['publishedDate'] ?? null) ? $last['publishedDate'] : null;
            $nextGuid = is_array($last) && is_string($last['guid'] ?? null) ? $last['guid'] : null;

            // Ohne Cursor oder mit demselben wie eben gaebe es nur dieselbe
            // Seite noch einmal.
            if ($nextBefore === null || $nextGuid === null || ($nextBefore === $before && $nextGuid === $lastGuid)) {
                break;
            }

            $before = $nextBefore;
            $lastGuid = $nextGuid;
        }

        return $posts;
    }

    /**
     * Die Felder, die eine Kachel braucht, aus einem Beitrag der Antwort -
     * oder null, wenn der Beitrag nicht auf die Website gehoert.
     *
     * Nicht auf die Website gehoert ein Beitrag, wenn ChurchTools ihn nicht
     * ausdruecklich als oeffentlich fuehrt (fehlt eine der Angaben, gilt das
     * als nicht oeffentlich), wenn er gesperrt ist, aus einer fremden Instanz
     * stammt, abgelaufen oder noch nicht veroeffentlicht ist.
     */
    public static function normalizePost(array $post, string $baseUrl, int $nowTimestamp): ?array
    {
        $id = (int) ($post['id'] ?? 0);
        $title = trim((string) ($post['title'] ?? ''));
        $content = trim((string) ($post['content'] ?? ''));
        $group = is_array($post['group'] ?? null) ? $post['group'] : [];
        $groupId = (int) ($group['domainIdentifier'] ?? 0);

        if ($id <= 0 || $groupId <= 0 || ($title === '' && $content === '')) {
            return null;
        }

        if (!self::isPublic($post) || !empty($post['isBanned']) || !self::isOwnInstance($post['instance'] ?? null, $baseUrl)) {
            return null;
        }

        $published = self::timestamp($post['publishedDate'] ?? null);
        $publication = self::timestamp($post['publicationDate'] ?? null);
        $expires = self::timestamp($post['expirationDate'] ?? null);

        if ($published === null || ($publication !== null && $publication > $nowTimestamp) || ($expires !== null && $expires <= $nowTimestamp)) {
            return null;
        }

        return [
            'id' => $id,
            'guid' => (string) ($post['guid'] ?? ''),
            'title' => $title,
            'content' => $content,
            // Lokale Zeit wie in der Termintabelle (siehe SyncEngine::toMysqlDate()),
            // damit mysql2date() im Template das Datum der Website zeigt.
            'published' => self::toMysqlDate($published),
            'expires' => $expires,
            'group_id' => $groupId,
            'group_name' => trim((string) ($group['title'] ?? '')),
            'group_url' => trailingslashit($baseUrl) . 'publicgroup/' . $groupId,
            'images' => self::imageUrls($post, $baseUrl),
        ];
    }

    /**
     * Beide Angaben muessen ausdruecklich stimmen. Traegt das Gruppenobjekt
     * seine eigene Sichtbarkeit mit (`domainAttributes.visibility`, an der
     * Referenzinstanz der Fall), muss auch die oeffentlich sein.
     */
    private static function isPublic(array $post): bool
    {
        if (($post['visibility'] ?? null) !== 'group_visible' || ($post['groupVisibility'] ?? null) !== 'public') {
            return false;
        }

        $groupVisibility = $post['group']['domainAttributes']['visibility'] ?? null;

        return $groupVisibility === null || $groupVisibility === 'public';
    }

    /**
     * Laut Spec kann die Liste Beitraege fremder Instanzen enthalten, mit
     * deren Angaben in `instance`. Eigene Beitraege kommen an der
     * Referenzinstanz mit `instance: null`; steht doch etwas darin, zaehlt
     * nur dieselbe Adresse als eigen.
     *
     * @param mixed $instance
     */
    private static function isOwnInstance($instance, string $baseUrl): bool
    {
        if ($instance === null || $instance === []) {
            return true;
        }

        if (!is_array($instance)) {
            return false;
        }

        $siteUrl = (string) ($instance['siteUrl'] ?? '');

        return $siteUrl !== '' && self::host($siteUrl) === self::host($baseUrl);
    }

    /**
     * Die Bildadressen, in der Groesse fuer Kachel und Popup. Nur Bilder der
     * eigenen Instanz: Sie werden ohne Anmeldung heruntergeladen, und eine
     * fremde Adresse in einem Beitrag soll WordPress nicht irgendwohin
     * schicken.
     *
     * @return list<string>
     */
    private static function imageUrls(array $post, string $baseUrl): array
    {
        $candidates = is_array($post['images'] ?? null) ? $post['images'] : [];

        if ($candidates === [] && is_array($post['imagesMeta'] ?? null)) {
            foreach ($post['imagesMeta'] as $meta) {
                $candidates[] = is_array($meta) ? ($meta['imageUrl'] ?? null) : null;
            }
        }

        $urls = [];

        foreach ($candidates as $url) {
            if (!is_string($url) || !str_starts_with($url, 'https://') || self::host($url) !== self::host($baseUrl)) {
                continue;
            }

            $sized = SyncEngine::sizedImageUrl($url);

            if (!in_array($sized, $urls, true)) {
                $urls[] = $sized;
            }

            if (count($urls) >= self::IMAGES_PER_POST) {
                break;
            }
        }

        return $urls;
    }

    private static function host(string $url): string
    {
        return strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    }

    /** @param mixed $value */
    private static function timestamp($value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private static function toMysqlDate(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    /**
     * Was nach einem gelungenen Abruf gespeichert wird - mit demselben Schutz
     * wie bei den Gruppen (GroupSync::nextHomepageData()): Eine leere Antwort
     * ersetzt einen nicht leeren Bestand erst beim dritten Lauf in Folge.
     * Abgelaufene Beitraege verschwinden trotzdem sofort, dafuer sorgt
     * visiblePosts() beim Anzeigen.
     *
     * @param array{fetched?: string, empty_runs?: int, posts?: array} $stored
     *
     * @return array{fetched: string, empty_runs: int, posts: list<array>}
     */
    public static function nextData(array $stored, array $posts, string $now): array
    {
        $storedPosts = is_array($stored['posts'] ?? null) ? array_values($stored['posts']) : [];

        if ($posts !== [] || $storedPosts === []) {
            return ['fetched' => $now, 'empty_runs' => 0, 'posts' => array_values($posts)];
        }

        $emptyRuns = (int) ($stored['empty_runs'] ?? 0) + 1;

        if ($emptyRuns >= self::EMPTY_RUNS_BEFORE_CLEAR) {
            return ['fetched' => $now, 'empty_runs' => 0, 'posts' => []];
        }

        return [
            'fetched' => (string) ($stored['fetched'] ?? ''),
            'empty_runs' => $emptyRuns,
            'posts' => $storedPosts,
        ];
    }

    /** @return array{fetched?: string, empty_runs?: int, posts?: array} */
    public static function storedData(): array
    {
        $data = get_option(self::DATA_OPTION, []);

        return is_array($data) ? $data : [];
    }

    /**
     * Die gespeicherten Beitraege, die gerade gezeigt werden duerfen: Der
     * Schalter ist an, und der Beitrag ist nicht seit dem letzten Lauf
     * abgelaufen. Die neuesten zuerst, wie ChurchTools sie liefert.
     *
     * @return list<array>
     */
    public static function visiblePosts(?int $nowTimestamp = null): array
    {
        if (!PostSettings::isEnabled()) {
            return [];
        }

        $nowTimestamp ??= current_datetime()->getTimestamp();
        $posts = self::storedData()['posts'] ?? [];

        return array_values(array_filter(
            is_array($posts) ? $posts : [],
            static fn ($post): bool => is_array($post)
                && (int) ($post['id'] ?? 0) > 0
                && (($post['expires'] ?? null) === null || (int) $post['expires'] > $nowTimestamp)
        ));
    }

    /**
     * Die Beitraege der genannten Gruppen, oder alle, wenn keine genannt ist.
     *
     * @param int[] $groupIds
     *
     * @return list<array>
     */
    public static function postsFor(array $groupIds, ?int $nowTimestamp = null): array
    {
        $posts = self::visiblePosts($nowTimestamp);

        if ($groupIds === []) {
            return $posts;
        }

        return array_values(array_filter(
            $posts,
            static fn (array $post): bool => in_array((int) ($post['group_id'] ?? 0), $groupIds, true)
        ));
    }

    /**
     * Die Gruppen, aus denen gespeicherte Beitraege stammen - die Auswahl in
     * Block und WPBakery. Nur, was ChurchTools gerade als oeffentlich liefert;
     * eine eigene Gruppenliste neben den Beitraegen gibt es nicht.
     *
     * @return array<int, string> Gruppen-ID => Name, nach Name sortiert
     */
    public static function selectableGroups(): array
    {
        $groups = [];

        foreach (self::visiblePosts() as $post) {
            $id = (int) ($post['group_id'] ?? 0);

            if ($id > 0 && !isset($groups[$id])) {
                $groups[$id] = (string) ($post['group_name'] ?? '') !== '' ? (string) $post['group_name'] : sprintf('#%d', $id);
            }
        }

        uasort($groups, 'strcasecmp');

        return $groups;
    }

    /** @return array<string, int> Bildadresse => Attachment-ID */
    public static function imageMap(): array
    {
        $map = get_option(self::IMAGES_OPTION, []);

        return is_array($map) ? array_map('intval', $map) : [];
    }

    /**
     * Welche Bilder die gespeicherten Beitraege brauchen, in der Reihenfolge
     * der Beitraege.
     *
     * @param list<array> $posts
     *
     * @return list<string>
     */
    public static function wantedImages(array $posts): array
    {
        $wanted = [];

        foreach ($posts as $post) {
            foreach ((array) ($post['images'] ?? []) as $url) {
                if (is_string($url) && $url !== '' && !in_array($url, $wanted, true)) {
                    $wanted[] = $url;
                }
            }
        }

        return $wanted;
    }

    /**
     * Importiert, was fehlt, und loescht, was kein Beitrag mehr braucht. Die
     * Adresse ist hier zugleich der Schluessel - ein geaendertes Bild hat in
     * ChurchTools eine neue Adresse und ist damit ein neues Bild.
     *
     * @param list<array> $posts
     */
    private static function syncImages(array $posts, ?ImageImportFailures $failures = null): void
    {
        $map = self::imageMap();
        $next = [];

        foreach (self::wantedImages($posts) as $url) {
            $previous = $map[$url] ?? null;

            if ($previous !== null && get_post_meta($previous, self::IMAGE_META_KEY, true) === $url) {
                $next[$url] = $previous;
                continue;
            }

            $imported = SyncEngine::importImage($url, self::IMAGE_META_KEY, 'churchtools-post-', $failures);

            if ($imported !== null) {
                $next[$url] = $imported;
            }
        }

        foreach ($map as $url => $attachmentId) {
            if (!isset($next[$url]) || $next[$url] !== $attachmentId) {
                wp_delete_attachment($attachmentId, true);
            }
        }

        update_option(self::IMAGES_OPTION, $next, false);
    }

    /**
     * Raeumt Beitraege, Bilder und Meldungen ab - nach dem Abschalten. Der
     * Zeitpunkt des letzten Laufs bleibt, er beschreibt die Vergangenheit.
     */
    private static function clear(): void
    {
        foreach (self::imageMap() as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }

        delete_option(self::DATA_OPTION);
        delete_option(self::IMAGES_OPTION);
        delete_option(self::ERROR_OPTION);
        delete_option(self::IMAGE_WARNING_OPTION);
    }

    /**
     * @return array{time: string, message: string}|null
     */
    public static function getLastError(): ?array
    {
        $error = get_option(self::ERROR_OPTION, null);

        if (!is_array($error) || !is_scalar($error['time'] ?? null) || !is_scalar($error['message'] ?? null)) {
            return null;
        }

        return ['time' => (string) $error['time'], 'message' => (string) $error['message']];
    }

    /**
     * @return array{time: string, count: int, reasons: string}|null
     */
    public static function getImageWarning(): ?array
    {
        return ImageImportFailures::read(self::IMAGE_WARNING_OPTION);
    }

    /**
     * Alles, was die Beitraege anlegen - fuer uninstall.php steht dieselbe
     * Liste dort noch einmal, die Klasse ist dort nicht geladen.
     */
    public static function optionNames(): array
    {
        return [
            PostSettings::OPTION_KEY,
            self::DATA_OPTION,
            self::IMAGES_OPTION,
            self::ERROR_OPTION,
            self::IMAGE_WARNING_OPTION,
            self::LAST_SYNC_OPTION,
        ];
    }

    /** Fuer den Knopf „Jetzt synchronisieren", wie GroupSync::runNow(). */
    public static function runNow(): void
    {
        if (!PostSettings::isEnabled()) {
            throw new RuntimeException(__('Die Synchronisation der Beiträge ist ausgeschaltet. Bitte zuerst unter „Beiträge → Synchronisation“ einschalten und speichern.', 'churchtools-plugin'));
        }

        if (Settings::getBaseUrl() === '') {
            throw new RuntimeException(__('Bitte zuerst unter „Einstellungen → Verbindung“ die ChurchTools-Instanz eintragen.', 'churchtools-plugin'));
        }

        if (!ApiKey::isUsable()) {
            throw new RuntimeException(ApiKey::unusableMessage());
        }

        if (!self::run()) {
            throw new RuntimeException(RunLock::busyMessage());
        }

        $error = self::getLastError();

        if ($error !== null) {
            throw new RuntimeException($error['message']);
        }
    }
}
