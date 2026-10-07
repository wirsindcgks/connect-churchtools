<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Posts;

use ChurchToolsPlugin\Posts\PostSettings;
use ChurchToolsPlugin\Posts\PostSync;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Sync\RunLock;
use PHPUnit\Framework\TestCase;

/**
 * Die Antwortform ist an zwei echten Instanzen abgelesen (2026-10-07,
 * ChurchTools 3.136/3.137, mit und ohne Key), mit ausgedachten Namen.
 */
final class PostSyncTest extends TestCase
{
    private const BASE = 'https://musterkirche.church.tools';

    /** 2026-10-07 12:00 in Berlin. */
    private const NOW = 1791367200;

    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_http();
        ctp_test_reset_deleted_attachments();
        ctp_test_reset_post_meta();
        ctp_test_reset_media();
        ctp_test_set_current_time('2026-10-07 12:00:00');
        ctp_test_install_wpdb();
    }

    public function testNormalizePostTakesTheFieldsOfARealPost(): void
    {
        $post = PostSync::normalizePost($this->post(7, 'Treff am Mittwoch'), self::BASE, self::NOW);

        $this->assertSame([
            'id' => 7,
            'guid' => 'GUID-7',
            'title' => 'Treff am Mittwoch',
            'content' => 'Wir treffen uns am Mittwoch.',
            'published' => '2026-09-25 22:53:50',
            'expires' => null,
            'group_id' => 44,
            'group_name' => 'Seniorenarbeit',
            'group_url' => 'https://musterkirche.church.tools/publicgroup/44',
            'images' => ['https://musterkirche.church.tools/images/8884/abc?w=1600&h=1600&fit=max'],
        ], $post);
    }

    /** Verfasser, Kommentare und Reaktionen nennen Personen - nichts davon wird gespeichert. */
    public function testNormalizePostKeepsNoPersonalData(): void
    {
        $post = PostSync::normalizePost($this->post(7, 'Treff', [
            'comments' => [['content' => 'Bin dabei', 'person' => ['title' => 'Erika Mustermann']]],
            'reactions' => [['emoji' => '👍', 'person' => ['title' => 'Max Mustermann']]],
        ]), self::BASE, self::NOW);

        $serialized = (string) json_encode($post, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Mustermann', $serialized);
        $this->assertStringNotContainsString('Bin dabei', $serialized);
    }

    /**
     * Der Key sieht mehr als ein Besucher. Nur was ChurchTools ausdruecklich
     * als oeffentlich fuehrt, kommt durch - eine fehlende Angabe ist keine.
     *
     * @dataProvider notPublicProvider
     */
    public function testPostsThatAreNotPublicAreDropped(array $overrides): void
    {
        $this->assertNull(PostSync::normalizePost($this->post(7, 'Intern', $overrides), self::BASE, self::NOW));
    }

    /** @return array<string, array{0: array}> */
    public static function notPublicProvider(): array
    {
        return [
            'nur fuer Gruppenmitglieder' => [['visibility' => 'group_intern']],
            'interne Gruppe' => [['groupVisibility' => 'intern']],
            'versteckte Gruppe' => [['groupVisibility' => 'hidden']],
            'eingeschraenkte Gruppe' => [['groupVisibility' => 'restricted']],
            'Sichtbarkeit fehlt' => [['visibility' => null]],
            'Gruppensichtbarkeit fehlt' => [['groupVisibility' => null]],
            'Gruppe selbst intern' => [['group' => self::groupRef(['visibility' => 'intern'])]],
            'gesperrt' => [['isBanned' => true]],
        ];
    }

    public function testPostsOfAnotherInstanceAreDropped(): void
    {
        $foreign = ['guid' => 'X', 'siteName' => 'Nachbargemeinde', 'siteUrl' => 'https://nachbar.church.tools'];
        $own = ['guid' => 'Y', 'siteName' => 'Musterkirche', 'siteUrl' => 'https://musterkirche.church.tools/'];

        $this->assertNull(PostSync::normalizePost($this->post(7, 'Fremd', ['instance' => $foreign]), self::BASE, self::NOW));
        $this->assertNotNull(PostSync::normalizePost($this->post(8, 'Eigen', ['instance' => $own]), self::BASE, self::NOW));
    }

    public function testExpiredAndNotYetPublishedPostsAreDropped(): void
    {
        $expired = $this->post(7, 'Vorbei', ['expirationDate' => '2026-10-07T09:59:59Z']);
        $running = $this->post(8, 'Laeuft', ['expirationDate' => '2026-10-22T19:53:00Z']);
        $scheduled = $this->post(9, 'Spaeter', ['publicationDate' => '2026-10-08T08:00:00Z']);

        $this->assertNull(PostSync::normalizePost($expired, self::BASE, self::NOW));
        $this->assertSame(1792698780, PostSync::normalizePost($running, self::BASE, self::NOW)['expires']);
        $this->assertNull(PostSync::normalizePost($scheduled, self::BASE, self::NOW));
    }

    /**
     * Bilder werden ohne Anmeldung geladen - nur von der eigenen Instanz und
     * hoechstens IMAGES_PER_POST je Beitrag.
     */
    public function testOnlyImagesOfTheOwnInstanceAreTakenAndAtMostFour(): void
    {
        $post = PostSync::normalizePost($this->post(7, 'Flyer', ['images' => [
            'https://evil.example/images/1/a',
            'http://musterkirche.church.tools/images/2/b',
            self::BASE . '/images/3/c',
            self::BASE . '/images/3/c',
            self::BASE . '/images/4/d',
            self::BASE . '/images/5/e',
            self::BASE . '/images/6/f',
            self::BASE . '/images/7/g',
        ]]), self::BASE, self::NOW);

        $this->assertSame([
            self::BASE . '/images/3/c?w=1600&h=1600&fit=max',
            self::BASE . '/images/4/d?w=1600&h=1600&fit=max',
            self::BASE . '/images/5/e?w=1600&h=1600&fit=max',
            self::BASE . '/images/6/f?w=1600&h=1600&fit=max',
        ], $post['images']);
    }

    public function testImagesFallBackToImagesMeta(): void
    {
        $post = PostSync::normalizePost($this->post(7, 'Flyer', [
            'images' => [],
            'imagesMeta' => [['imageUrl' => self::BASE . '/images/9/z', 'aspectRatio' => 0.7]],
        ]), self::BASE, self::NOW);

        $this->assertSame([self::BASE . '/images/9/z?w=1600&h=1600&fit=max'], $post['images']);
    }

    public function testPostsWithoutGroupOrWithoutAnyTextAreDropped(): void
    {
        $this->assertNull(PostSync::normalizePost($this->post(7, 'Ohne Gruppe', ['group' => null]), self::BASE, self::NOW));
        $this->assertNull(PostSync::normalizePost($this->post(8, '', ['content' => '']), self::BASE, self::NOW));
        $this->assertNotNull(PostSync::normalizePost($this->post(9, '', ['content' => 'Nur Text']), self::BASE, self::NOW));
    }

    /** Ausgeschaltet fragt das Plugin ChurchTools nicht - und raeumt ab, was da ist. */
    public function testWhenSwitchedOffNothingIsAskedAndEverythingIsRemoved(): void
    {
        $this->configure(false);
        ctp_test_set_option(PostSync::DATA_OPTION, ['fetched' => '2026-10-06 12:00:00', 'empty_runs' => 0, 'posts' => [['id' => 1]]]);
        ctp_test_set_option(PostSync::IMAGES_OPTION, [self::BASE . '/images/1/a' => 301]);
        ctp_test_set_option(PostSync::ERROR_OPTION, ['time' => 'x', 'message' => 'alt']);

        PostSync::run();

        $this->assertSame([], ctp_test_http_calls());
        $this->assertSame([301], ctp_test_deleted_attachments());
        $this->assertSame([], PostSync::storedData());
        $this->assertSame([], PostSync::imageMap());
        $this->assertNull(PostSync::getLastError());
    }

    public function testRunAsksOnlyForPublicPostsWithTheKeyAndStoresThem(): void
    {
        $this->configure(true);
        ctp_test_queue_http([
            $this->post(7, 'Neu', ['images' => [], 'imagesMeta' => []]),
            $this->post(4, 'Intern', ['visibility' => 'group_intern', 'images' => [], 'imagesMeta' => []]),
        ]);
        ctp_test_queue_http([]);

        PostSync::run();

        $calls = ctp_test_http_calls();
        $this->assertStringStartsWith(self::BASE . '/api/posts?', $calls[0]['url']);
        $this->assertStringContainsString('group_visibility=public', $calls[0]['url']);
        $this->assertStringContainsString('post_visibility=group_visible', $calls[0]['url']);
        $this->assertSame('Login beitrags-token', $calls[0]['args']['headers']['Authorization']);

        $this->assertSame(['Neu'], array_column(PostSync::visiblePosts(self::NOW), 'title'));
        $this->assertNull(PostSync::getLastError());
        $this->assertSame('2026-10-07 12:00:00', get_option(PostSync::LAST_SYNC_OPTION));
    }

    /**
     * Seiten gibt es nur ueber den Cursor: `before` und
     * `last_post_identifier` aus dem letzten Eintrag der Antwort - auch wenn
     * dieser herausgefiltert wurde.
     */
    public function testFurtherPagesFollowTheCursorOfTheLastAnsweredPost(): void
    {
        $this->configure(true);
        ctp_test_queue_http([
            $this->post(9, 'Erste Seite', ['images' => [], 'imagesMeta' => []]),
            $this->post(8, 'Intern', ['visibility' => 'group_intern', 'publishedDate' => '2026-09-20T10:00:00Z']),
        ]);
        ctp_test_queue_http([$this->post(5, 'Zweite Seite', ['images' => [], 'imagesMeta' => [], 'publishedDate' => '2026-09-01T10:00:00Z'])]);
        ctp_test_queue_http([]);

        PostSync::run();

        $calls = ctp_test_http_calls();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('before=2026-09-20T10%3A00%3A00Z', $calls[1]['url']);
        $this->assertStringContainsString('last_post_identifier=GUID-8', $calls[1]['url']);
        $this->assertSame(['Erste Seite', 'Zweite Seite'], array_column(PostSync::visiblePosts(self::NOW), 'title'));
    }

    /** Ein ausgefallener Abruf nimmt der Website nicht die Beitraege. */
    public function testAFailedRequestKeepsTheStoredPostsAndRecordsTheError(): void
    {
        $this->configure(true);
        ctp_test_set_option(PostSync::DATA_OPTION, ['fetched' => '2026-10-06 12:00:00', 'empty_runs' => 0, 'posts' => [$this->stored(1, 'Bestand')]]);
        ctp_test_queue_raw_http('Service Unavailable', 503);

        PostSync::run();

        $this->assertSame(['Bestand'], array_column(PostSync::visiblePosts(self::NOW), 'title'));
        $this->assertStringContainsString('503', PostSync::getLastError()['message']);
        $this->assertFalse(get_option(PostSync::LAST_SYNC_OPTION));
    }

    public function testAnEmptyAnswerClearsStoredPostsOnlyOnTheThirdRunInARow(): void
    {
        $stored = ['fetched' => '2026-10-06 12:00:00', 'empty_runs' => 0, 'posts' => [$this->stored(1, 'Bestand')]];

        $first = PostSync::nextData($stored, [], '2026-10-07 12:00:00');
        $second = PostSync::nextData($first, [], '2026-10-07 13:00:00');
        $third = PostSync::nextData($second, [], '2026-10-07 14:00:00');

        $this->assertSame(['Bestand'], array_column($first['posts'], 'title'));
        $this->assertSame('2026-10-06 12:00:00', $first['fetched']);
        $this->assertSame(['Bestand'], array_column($second['posts'], 'title'));
        $this->assertSame([], $third['posts']);
        $this->assertSame([], PostSync::nextData([], [], 'x')['posts']);
    }

    /** Ein seit dem letzten Lauf abgelaufener Beitrag verschwindet sofort, nicht erst mit dem naechsten. */
    public function testVisiblePostsHideWhatHasExpiredSinceTheLastRun(): void
    {
        $this->configure(true);
        ctp_test_set_option(PostSync::DATA_OPTION, ['posts' => [
            array_merge($this->stored(1, 'Abgelaufen'), ['expires' => self::NOW - 1]),
            array_merge($this->stored(2, 'Laeuft'), ['expires' => self::NOW + 60]),
            $this->stored(3, 'Ohne Ablauf'),
        ]]);

        $this->assertSame(['Laeuft', 'Ohne Ablauf'], array_column(PostSync::visiblePosts(self::NOW), 'title'));
    }

    /** Wird der Schalter umgelegt, zeigt die Website sofort nichts mehr - nicht erst nach dem Aufraeumlauf. */
    public function testNothingIsShownWhileSwitchedOff(): void
    {
        $this->configure(false);
        ctp_test_set_option(PostSync::DATA_OPTION, ['posts' => [$this->stored(1, 'Bestand')]]);

        $this->assertSame([], PostSync::visiblePosts(self::NOW));
        $this->assertSame([], PostSync::selectableGroups());
    }

    public function testPostsForFiltersByGroupAndSelectableGroupsAreSortedByName(): void
    {
        $this->configure(true);
        ctp_test_set_option(PostSync::DATA_OPTION, ['posts' => [
            array_merge($this->stored(1, 'A'), ['group_id' => 44, 'group_name' => 'Seniorenarbeit']),
            array_merge($this->stored(2, 'B'), ['group_id' => 31, 'group_name' => 'Jugend']),
            array_merge($this->stored(3, 'C'), ['group_id' => 44, 'group_name' => 'Seniorenarbeit']),
        ]]);

        $this->assertSame(['A', 'C'], array_column(PostSync::postsFor([44], self::NOW), 'title'));
        $this->assertSame(['A', 'B', 'C'], array_column(PostSync::postsFor([], self::NOW), 'title'));
        $this->assertSame([31 => 'Jugend', 44 => 'Seniorenarbeit'], PostSync::selectableGroups());
    }

    public function testPostImagesUseTheirOwnSourceMetaKey(): void
    {
        $this->assertNotSame('_ctp_source_image_url', PostSync::IMAGE_META_KEY);
        $this->assertNotSame('_ctp_group_source_image_url', PostSync::IMAGE_META_KEY);
    }

    /** Ein unveraendertes Bild bleibt, eines, das kein Beitrag mehr braucht, geht. */
    public function testUnchangedImagesAreKeptAndUnusedOnesDeleted(): void
    {
        $this->configure(true);
        $kept = self::BASE . '/images/8884/abc?w=1600&h=1600&fit=max';
        ctp_test_set_option(PostSync::IMAGES_OPTION, [$kept => 301, self::BASE . '/images/1/alt?w=1600&h=1600&fit=max' => 302]);
        ctp_test_set_post_meta(301, PostSync::IMAGE_META_KEY, $kept);
        ctp_test_queue_http([$this->post(7, 'Mit Bild')]);
        ctp_test_queue_http([]);

        PostSync::run();

        $this->assertSame([$kept => 301], PostSync::imageMap());
        $this->assertSame([302], ctp_test_deleted_attachments());
        $this->assertSame([], ctp_test_download_calls());
    }

    public function testAFailedImageIsAWarningNotAnError(): void
    {
        $this->configure(true);
        ctp_test_queue_http([$this->post(7, 'Mit Bild')]);
        ctp_test_queue_http([]);
        ctp_test_queue_download(new \WP_Error('http_404', 'Unauthorized', ['code' => 401]));

        PostSync::run();

        $this->assertNull(PostSync::getLastError());
        $this->assertSame(['Mit Bild'], array_column(PostSync::visiblePosts(self::NOW), 'title'));
        $this->assertSame(1, PostSync::getImageWarning()['count']);
    }

    public function testWithoutAKeyNothingIsAskedAndTheErrorSaysWhy(): void
    {
        $this->configure(true, '');

        PostSync::run();

        $this->assertSame([], ctp_test_http_calls());
        $this->assertStringContainsString('API-Key', PostSync::getLastError()['message']);
    }

    public function testASecondRunWhileTheFirstHoldsTheLockDoesNothing(): void
    {
        $this->configure(true);
        $token = RunLock::acquire(PostSync::LOCK);

        $this->assertFalse(PostSync::run());
        $this->assertSame([], ctp_test_http_calls());

        RunLock::release(PostSync::LOCK, (string) $token);
    }

    public function testRunNowRefusesWhileSwitchedOff(): void
    {
        $this->configure(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ausgeschaltet');

        PostSync::runNow();
    }

    private function configure(bool $enabled, string $apiKey = 'beitrags-token'): void
    {
        ctp_test_set_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => Crypto::encrypt($apiKey)]);
        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => $enabled, 'sync_interval' => 'hourly']);
    }

    /** @return array<string, mixed> */
    private static function groupRef(array $attributes = []): array
    {
        return [
            'apiUrl' => self::BASE . '/api/groups/44',
            'domainIdentifier' => '44',
            'domainType' => 'group',
            'frontendUrl' => self::BASE . '/groups/44',
            'title' => 'Seniorenarbeit',
            'domainAttributes' => array_merge(['visibility' => 'public', 'groupTypeId' => 5], $attributes),
        ];
    }

    /** Ein Beitrag in der Form der API-Antwort. */
    private function post(int $id, string $title, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'guid' => 'GUID-' . $id,
            'title' => $title,
            'content' => 'Wir treffen uns am Mittwoch.',
            'publishedDate' => '2026-09-25T20:53:50Z',
            'publicationDate' => null,
            'expirationDate' => null,
            'visibility' => 'group_visible',
            'groupVisibility' => 'public',
            'isBanned' => false,
            'instance' => null,
            'commentsActive' => true,
            'actor' => ['title' => 'Erika Mustermann', 'domainType' => 'person', 'domainIdentifier' => '12'],
            'group' => self::groupRef(),
            'images' => [self::BASE . '/images/8884/abc'],
            'imagesMeta' => [['imageUrl' => self::BASE . '/images/8884/abc', 'aspectRatio' => 0.7]],
        ], $overrides);
    }

    /** Ein Beitrag in der gespeicherten Form. */
    private function stored(int $id, string $title): array
    {
        return [
            'id' => $id,
            'guid' => 'GUID-' . $id,
            'title' => $title,
            'content' => 'Text',
            'published' => '2026-10-01 10:00:00',
            'expires' => null,
            'group_id' => 44,
            'group_name' => 'Seniorenarbeit',
            'group_url' => self::BASE . '/publicgroup/44',
            'images' => [],
        ];
    }
}
