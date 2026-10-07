<?php

declare(strict_types=1);

namespace {
    if (!function_exists('has_shortcode')) {
        /** So weit wie WordPress' has_shortcode() fuer diese Tests reicht: der Tag in eckigen Klammern. */
        function has_shortcode(string $content, string $tag): bool
        {
            return (bool) preg_match('/\[' . preg_quote($tag, '/') . '(?=[\s\]\/])/', $content);
        }
    }
}

namespace ChurchToolsPlugin\Tests\Frontend {

use ChurchToolsPlugin\Frontend\Assets;
use ChurchToolsPlugin\Frontend\Shortcode;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Stylesheet und Skript laden nur auf Seiten, deren Inhalt einen Shortcode des
 * Plugins enthaelt. Beim Bau von [ctp_posts] fehlte der neue Shortcode in
 * dieser Liste: Kacheln ohne Stylesheet, und der Klick auf eine Kachel fuehrte
 * nach ChurchTools statt ins Popup (2026-10-07, erst im Browser gesehen).
 */
final class AssetsTest extends TestCase
{
    /** @dataProvider pagesProvider */
    public function testThePageDecidesWhetherTheAssetsLoad(string $content, bool $expected): void
    {
        $this->assertSame($expected, Assets::contentUsesShortcode($content));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function pagesProvider(): array
    {
        return [
            'Termine' => ['[ctp_events layout="grid"]', true],
            'Gruppen' => ['<p>Text</p>[ctp_groups homepage="Kleingruppen"]', true],
            'Beitraege' => ["<!-- wp:shortcode -->\n[ctp_posts]\n<!-- /wp:shortcode -->", true],
            'ohne Shortcode' => ['<p>Nur Text über ctp_posts</p>', false],
        ];
    }

    public function testEveryShortcodeLoadsTheFrontendAssets(): void
    {
        $this->assertSame(Shortcode::TAGS, Assets::SHORTCODES);
        $this->assertContains('ctp_posts', Assets::SHORTCODES);
    }

    /** Jeder Shortcode hat eine Methode, die es auch gibt. */
    public function testEveryShortcodeHasARenderMethod(): void
    {
        $methods = (new ReflectionClassConstant(Shortcode::class, 'METHODS'))->getValue();

        $this->assertSame(Shortcode::TAGS, array_keys($methods));

        foreach ($methods as $method) {
            $this->assertTrue(method_exists(Shortcode::class, $method), $method);
        }
    }
}
}
