<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Helper;

use Panth\XmlSitemap\Helper\PathResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathResolverTest extends TestCase
{
    private PathResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PathResolver();
    }

    #[DataProvider('relativeDirProvider')]
    public function testResolveRelativeDir(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolveRelativeDir($input, 'default'));
    }

    public static function relativeDirProvider(): array
    {
        return [
            'empty uses default'   => ['', 'xmlsitemap/default'],
            'whitespace default'   => ['   ', 'xmlsitemap/default'],
            'placeholder replaced' => ['maps/{store_code}/', 'maps/default'],
            'dots and slashes'     => ['/./a//b/', 'a/b'],
            'backslashes'          => ['a\\b', 'a/b'],
            'root'                 => ['/', ''],
            'traversal rejected'   => ['../etc', 'xmlsitemap/default'],
            'bad chars rejected'   => ['a/b c', 'xmlsitemap/default'],
            'pub dir'              => ['pub/media', 'pub/media'],
        ];
    }

    public function testBuildSitemapUrlWithDir(): void
    {
        $this->assertSame(
            'https://shop.test/xmlsitemap/default/sitemap.xml',
            $this->resolver->buildSitemapUrl('https://shop.test/', '/xmlsitemap/default/')
        );
    }

    public function testBuildSitemapUrlAtRootWithCustomFile(): void
    {
        $this->assertSame(
            'https://shop.test/index.xml',
            $this->resolver->buildSitemapUrl('https://shop.test', '', '/index.xml')
        );
    }

    public static function filenameProvider(): array
    {
        return [
            'default'        => ['sitemap.xml', true],
            'store suffix'   => ['sitemap_luma.xml', true],
            'dotted'         => ['sitemap.en-gb.xml', true],
            'digit start'    => ['2026-sitemap.xml', true],
            'no extension'   => ['sitemap', false],
            'gzip index'     => ['sitemap.xml.gz', false],
            'other ext'      => ['sitemap.php', false],
            'upper ext'      => ['sitemap.XML', false],
            'leading dot'    => ['.sitemap.xml', false],
            'double dot'     => ['a..xml', false],
            'traversal'      => ['../sitemap.xml', false],
            'folder'         => ['seo/sitemap.xml', false],
            'backslash'      => ['seo\\sitemap.xml', false],
            'space'          => ['site map.xml', false],
            'empty'          => ['', false],
            'too long'       => [str_repeat('a', 125) . '.xml', false],
        ];
    }

    #[DataProvider('filenameProvider')]
    public function testIsValidFilename(string $name, bool $valid): void
    {
        $this->assertSame($valid, $this->resolver->isValidFilename($name));
    }

    public static function outputPathProvider(): array
    {
        return [
            'root'        => ['/', true],
            'folder'      => ['seo', true],
            'placeholder' => ['seo/{store_code}/', true],
            'prefixed'    => ['xmlsitemap/sample-{store_code}/', true],
            'empty'       => ['', false],
            'traversal'   => ['../app/etc', false],
            'inner dots'  => ['seo/../../app', false],
            'backslash'   => ['seo\\x', false],
            'bad chars'   => ['seo/<b>', false],
            'too long'    => [str_repeat('a', 256), false],
        ];
    }

    #[DataProvider('outputPathProvider')]
    public function testIsValidOutputPath(string $path, bool $valid): void
    {
        $this->assertSame($valid, $this->resolver->isValidOutputPath($path));
    }

    public function testResolveFilenameFallsBack(): void
    {
        $this->assertSame('sitemap_luma.xml', $this->resolver->resolveFilename(' sitemap_luma.xml ', 'x.xml'));
        $this->assertSame('legacy.xml', $this->resolver->resolveFilename(null, 'legacy.xml'));
        $this->assertSame('legacy.xml', $this->resolver->resolveFilename('../evil.xml', 'legacy.xml'));
        $this->assertSame('sitemap.xml', $this->resolver->resolveFilename('', 'bad name'));
        $this->assertSame('sitemap.xml', $this->resolver->resolveFilename(null));
    }

    public function testFileBaseAndRelativeFile(): void
    {
        $this->assertSame('sitemap', $this->resolver->getFileBase('sitemap.xml'));
        $this->assertSame('sitemap.en', $this->resolver->getFileBase('sitemap.en.xml'));
        $this->assertSame('sitemap', $this->resolver->getFileBase('.xml'));
        $this->assertSame('sitemap.xml', $this->resolver->buildRelativeFile('', 'sitemap.xml'));
        $this->assertSame('seo/luma/s.xml', $this->resolver->buildRelativeFile('/seo/luma/', 's.xml'));
    }

    public function testNormaliseUrlCollapsesSlashesButKeepsScheme(): void
    {
        $this->assertSame('https://a.test/b/c', $this->resolver->normaliseUrl('https://a.test//b///c'));
        $this->assertSame('/a/b', $this->resolver->normaliseUrl('//a//b'));
        $this->assertSame('', $this->resolver->normaliseUrl(''));
    }
}
