<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\PostListRenderer;
use ChurchToolsPlugin\Posts\PostSettings;
use ChurchToolsPlugin\Posts\PostSync;
use PHPUnit\Framework\TestCase;

final class PostListRendererTest extends TestCase
{
    private const IMAGE = 'https://musterkirche.church.tools/images/1/a?w=1600&h=1600&fit=max';
    private const SECOND_IMAGE = 'https://musterkirche.church.tools/images/2/b?w=1600&h=1600&fit=max';

    protected function setUp(): void
    {
        ctp_test_reset_options();
        ctp_test_reset_attachments();
        ctp_test_set_current_time('2026-10-07 12:00:00');
        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => true]);
        ctp_test_set_option('date_format', 'd.m.Y');
    }

    /** @dataProvider limitProvider */
    public function testNormalizeLimit($given, int $expected): void
    {
        $this->assertSame($expected, PostListRenderer::normalizeLimit($given));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function limitProvider(): array
    {
        return [
            'Standard' => ['', PostListRenderer::DEFAULT_LIMIT],
            'Zahl als Text' => ['3', 3],
            'null heisst alle' => [0, 0],
            'gedeckelt' => [500, PostSync::MAX_POSTS],
            'negativ' => [-2, PostListRenderer::DEFAULT_LIMIT],
            'Unsinn' => ['abc', PostListRenderer::DEFAULT_LIMIT],
        ];
    }

    public function testGroupsAndLimitSelectTheNewestPostsOfThoseGroups(): void
    {
        $this->storePosts([
            $this->post(3, 'Neu', 44),
            $this->post(2, 'Jugend', 31),
            $this->post(1, 'Alt', 44),
        ]);

        $this->assertSame(['Neu', 'Alt'], array_column(PostListRenderer::selectPosts('44', 0), 'title'));
        $this->assertSame(['Neu'], array_column(PostListRenderer::selectPosts('44', 1), 'title'));
        $this->assertSame(['Neu', 'Jugend', 'Alt'], array_column(PostListRenderer::selectPosts('', 0), 'title'));
    }

    /** Eine Auswahl, aus der keine ID wird, ist eine misslungene Auswahl und nicht „alle". */
    public function testAnUnusableSelectionShowsNothing(): void
    {
        $this->storePosts([$this->post(1, 'Bestand', 44)]);

        $this->assertSame([], PostListRenderer::selectPosts('abc', 0));
    }

    /** Die Kachel zeigt das erste importierte Bild, das Popup die weiteren. */
    public function testTheFirstImportedImageIsTheCardAndTheRestTheGallery(): void
    {
        ctp_test_set_attachment_url(301, 'https://example.org/uploads/a.webp');
        ctp_test_set_attachment_url(302, 'https://example.org/uploads/b.webp');

        $posts = PostListRenderer::preparePosts([
            array_merge($this->post(1, 'Flyer', 44), ['images' => [self::IMAGE, self::SECOND_IMAGE]]),
            array_merge($this->post(2, 'Nicht importiert', 44), ['images' => ['https://musterkirche.church.tools/images/9/z']]),
        ], [self::IMAGE => 301, self::SECOND_IMAGE => 302]);

        $this->assertSame('https://example.org/uploads/a.webp', $posts[0]['image_src']);
        $this->assertSame(['https://example.org/uploads/b.webp'], array_column($posts[0]['gallery'], 'src'));
        $this->assertSame('', $posts[1]['image_src'], 'Ohne Import kein Rueckfall auf die ChurchTools-Adresse.');
        $this->assertTrue($posts[1]['show_media'], 'Die Bildflaeche steht an jeder Kachel, sobald eine ein Bild hat.');
    }

    public function testHiddenElementsOfTheDesignTabApply(): void
    {
        ctp_test_set_attachment_url(301, 'https://example.org/uploads/a.webp');

        $post = PostListRenderer::preparePosts(
            [array_merge($this->post(1, 'Flyer', 44), ['images' => [self::IMAGE]])],
            [self::IMAGE => 301],
            ['media', 'date', 'calendar', 'excerpt']
        )[0];

        $this->assertSame('', $post['image_src']);
        $this->assertFalse($post['show_media']);
        $this->assertSame('', $post['date_label']);
        $this->assertSame('', $post['group_label']);
        $this->assertSame('', $post['excerpt_html']);
        $this->assertNotSame('', $post['description_html'], 'Den ganzen Text zeigt das Popup trotzdem.');
    }

    public function testRenderEscapesAndShowsDateGroupAndPopup(): void
    {
        $this->storePosts([array_merge($this->post(1, 'Kuchen <b>&</b> Kaffee', 44), ['content' => "Erste Zeile\nMehr unter info@musterkirche.de"])]);

        $html = (new PostListRenderer())->render(['columns' => 9]);

        $this->assertStringContainsString('Kuchen &lt;b&gt;&amp;&lt;/b&gt; Kaffee', $html);
        $this->assertStringContainsString('01.10.2026', $html);
        $this->assertStringContainsString('Seniorenarbeit', $html);
        $this->assertStringContainsString('href="https://musterkirche.church.tools/publicgroup/44"', $html);
        $this->assertStringContainsString('data-ctp-modal="1"', $html);
        $this->assertStringContainsString('ctp-events__detail-template', $html);
        $this->assertStringContainsString('--ctp-columns:6;', $html);
        $this->assertStringNotContainsString('info@musterkirche.de', $html, 'E-Mail-Adressen stehen verschleiert im Quelltext.');
        $this->assertStringNotContainsString('ctp-events__cta', $html, 'Ein Beitrag hat in ChurchTools keine oeffentliche Seite.');
    }

    public function testRenderShowsTheEmptyStateWhileSwitchedOff(): void
    {
        $this->storePosts([$this->post(1, 'Bestand', 44)]);
        ctp_test_set_option(PostSettings::OPTION_KEY, ['enabled' => false]);

        $html = (new PostListRenderer())->render([]);

        $this->assertStringContainsString('Zurzeit gibt es keine Beiträge.', $html);
        $this->assertStringNotContainsString('Bestand', $html);
    }

    public function testTheFeaturedLayoutUsesItsOwnTemplate(): void
    {
        $this->storePosts([$this->post(1, 'Gross', 44)]);

        $html = (new PostListRenderer())->render(['layout' => 'featured', 'limit' => 1]);

        $this->assertStringContainsString('ctp-posts--featured', $html);
        $this->assertStringContainsString('ctp-posts__feature', $html);
        $this->assertStringNotContainsString('ctp-posts__card', $html);
    }

    public function testAnUnknownLayoutFallsBackToTheGrid(): void
    {
        $this->storePosts([$this->post(1, 'Raster', 44)]);

        $this->assertStringContainsString('ctp-posts__card', (new PostListRenderer())->render(['layout' => 'liste']));
    }

    private function storePosts(array $posts): void
    {
        ctp_test_set_option(PostSync::DATA_OPTION, ['fetched' => '2026-10-07 11:00:00', 'empty_runs' => 0, 'posts' => $posts]);
    }

    private function post(int $id, string $title, int $groupId): array
    {
        return [
            'id' => $id,
            'guid' => 'GUID-' . $id,
            'title' => $title,
            'content' => 'Wir treffen uns am Mittwoch.',
            'published' => '2026-10-01 10:00:00',
            'expires' => null,
            'group_id' => $groupId,
            'group_name' => $groupId === 44 ? 'Seniorenarbeit' : 'Jugend',
            'group_url' => 'https://musterkirche.church.tools/publicgroup/' . $groupId,
            'images' => [],
        ];
    }
}
