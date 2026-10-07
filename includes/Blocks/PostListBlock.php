<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Blocks;

use ChurchToolsPlugin\Frontend\PostListRenderer;
use ChurchToolsPlugin\Posts\PostSync;

final class PostListBlock
{
    /** Von WordPress aus block.json abgeleitet, siehe EventListBlock::EDITOR_SCRIPT_HANDLE. */
    private const EDITOR_SCRIPT_HANDLE = 'churchtools-plugin-post-list-editor-script';

    public function register(): void
    {
        add_action('init', [$this, 'registerBlockType']);
        add_action('enqueue_block_editor_assets', [$this, 'localizeGroups']);
    }

    public function registerBlockType(): void
    {
        register_block_type(CTP_PLUGIN_DIR . 'blocks/post-list', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * Die Auswahl im Editor: die Gruppen, aus denen gerade Beitraege
     * gespeichert sind (PostSync::selectableGroups()) - wie die einzeln
     * waehlbaren Gruppen im Block „ChurchTools Gruppen".
     */
    public function localizeGroups(): void
    {
        wp_add_inline_script(
            self::EDITOR_SCRIPT_HANDLE,
            'window.ctpBlockPostGroups = ' . wp_json_encode(self::groupChoices()) . ';',
            'before'
        );
    }

    /** @return list<array{id: int, name: string}> */
    public static function groupChoices(): array
    {
        $choices = [];

        foreach (PostSync::selectableGroups() as $id => $name) {
            $choices[] = ['id' => (int) $id, 'name' => $name];
        }

        return $choices;
    }

    /** Im Block-Wrapper, siehe EventListBlock::render(). */
    public function render(array $attributes): string
    {
        return EventListBlock::wrap((new PostListRenderer())->render([
            'groups' => (string) ($attributes['groups'] ?? ''),
            'layout' => (string) ($attributes['layout'] ?? 'grid'),
            'columns' => (int) ($attributes['columns'] ?? 3),
            'limit' => (int) ($attributes['limit'] ?? PostListRenderer::DEFAULT_LIMIT),
        ]));
    }
}
