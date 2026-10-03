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

    public function testNormaliseUrlCollapsesSlashesButKeepsScheme(): void
    {
        $this->assertSame('https://a.test/b/c', $this->resolver->normaliseUrl('https://a.test//b///c'));
        $this->assertSame('/a/b', $this->resolver->normaliseUrl('//a//b'));
        $this->assertSame('', $this->resolver->normaliseUrl(''));
    }
}
