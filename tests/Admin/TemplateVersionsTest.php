<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\TemplateVersions;
use PHPUnit\Framework\TestCase;

final class TemplateVersionsTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    /** Ohne Angabe im Original waere jede Kopie „veraltet“ gegen eine leere Version. */
    public function testEveryBundledTemplateCarriesAVersion(): void
    {
        foreach (TemplateVersions::TEMPLATES as $file) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', TemplateVersions::bundledVersion($file), $file);
        }
    }

    public function testReadsTheVersionFromTheDocblock(): void
    {
        $this->assertSame('1.39.0', TemplateVersions::read($this->copy(" * @version 1.39.0\n")));
        $this->assertNull(TemplateVersions::read($this->copy('')));
        $this->assertNull(TemplateVersions::read($this->copy(" * @version neu\n")));
        $this->assertNull(TemplateVersions::read('/gibt/es/nicht.php'));
    }

    /** Eine unter Windows bearbeitete Kopie haette sonst nie eine Version, der Hinweis bliebe stehen. */
    public function testReadsTheVersionWithWindowsLineEndings(): void
    {
        $path = $this->copy('');
        file_put_contents($path, "<?php\r\n/**\r\n * @version 1.39.0\r\n * @var array \$args\r\n */\r\n");

        $this->assertSame('1.39.0', TemplateVersions::read($path));
    }

    public function testOnlyOlderCopiesAndCopiesWithoutVersionAreOutdated(): void
    {
        $plugin = TemplateVersions::bundledVersion('group-grid.php');
        $copies = [
            'group-grid.php' => $this->copy(" * @version 0.0.1\n"),
            'post-grid.php' => $this->copy(''),
            'event-list.php' => $this->copy(' * @version ' . TemplateVersions::bundledVersion('event-list.php') . "\n"),
            'event-grid.php' => $this->copy(' * @version 99.0.0' . "\n"),
        ];

        $outdated = TemplateVersions::outdated(static fn (string $file): string => $copies[$file] ?? '');

        $this->assertSame(['group-grid.php', 'post-grid.php'], array_column($outdated, 'file'));
        $this->assertNull($outdated[1]['theme']);
        $this->assertSame($plugin, $outdated[0]['plugin']);
    }

    public function testAThemeWithoutCopiesHasNothingOutdated(): void
    {
        $this->assertSame([], TemplateVersions::outdated(static fn (string $file): string => ''));
    }

    private function copy(string $versionLine): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ctp-tpl');
        file_put_contents($path, "<?php\n\n/**\n * Kopie im Theme.\n *\n" . $versionLine . " * @var array \$args\n */\n");
        $this->files[] = $path;

        return $path;
    }
}
