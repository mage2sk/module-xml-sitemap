<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Panth\XmlSitemap\Model\Sitemap\IndexWriter;
use Panth\XmlSitemap\Model\Sitemap\ShardWriter;
use Panth\XmlSitemap\Model\Sitemap\UrlElementWriter;
use PHPUnit\Framework\TestCase;

class ShardAndIndexWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/xmlsitemap_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testShardWriterWritesCountsAndClosesFile(): void
    {
        $writer = new ShardWriter(new UrlElementWriter());
        $path = $this->dir . '/nested/sitemap-1.xml';
        $writer->open($path, 'https://a/style.xsl?x=1&y=2');
        $writer->writeUrl(['loc' => 'https://a/1']);
        $writer->writeUrl(['loc' => '']);
        $writer->writeUrl(['loc' => 'https://a/2']);

        $this->assertSame(2, $writer->getCount());
        $this->assertGreaterThan(0, $writer->getFileSize());
        $this->assertSame($path, $writer->close());
        $this->assertSame($path, $writer->close(), 'second close is a no-op');

        $xml = (string) file_get_contents($path);
        $this->assertStringContainsString('href="https://a/style.xsl?x=1&amp;y=2"', $xml);
        $doc = simplexml_load_string($xml);
        $this->assertCount(2, $doc->url);
    }

    public function testShardWriterGzipReplacesPlainFile(): void
    {
        $writer = new ShardWriter(new UrlElementWriter());
        $path = $this->dir . '/s.xml';
        $writer->open($path, null, ['gzip' => true]);
        $writer->writeUrl(['loc' => 'https://a/1']);
        $result = $writer->close();

        $this->assertSame($path . '.gz', $result);
        $this->assertSame($result, $writer->getPath());
        $this->assertFileDoesNotExist($path);
        $this->assertStringContainsString('<loc>https://a/1</loc>', (string) gzdecode((string) file_get_contents($result)));
    }

    public function testShardWriterRejectsWritesBeforeOpen(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ShardWriter(new UrlElementWriter()))->writeUrl(['loc' => 'https://a/']);
    }

    public function testShardWriterDefaultsHreflangAndVideoOn(): void
    {
        $writer = new ShardWriter(new UrlElementWriter());
        $path = $this->dir . '/d.xml';
        $writer->open($path);
        $writer->close();
        $xml = (string) file_get_contents($path);
        $this->assertStringContainsString('xmlns:xhtml', $xml);
        $this->assertStringContainsString('xmlns:video', $xml);
    }

    public function testIndexWriterWritesShardEntries(): void
    {
        $path = $this->dir . '/idx/sitemap.xml';
        $result = (new IndexWriter())->write($path, [
            ['loc' => 'https://a/s1.xml', 'lastmod' => '2026-01-01'],
            ['loc' => 'https://a/s2.xml'],
        ], 'https://a/s.xsl');

        $this->assertSame($path, $result);
        $xml = (string) file_get_contents($path);
        $this->assertStringContainsString('xml-stylesheet', $xml);
        $doc = simplexml_load_string($xml);
        $this->assertSame('sitemapindex', $doc->getName());
        $this->assertCount(2, $doc->sitemap);
        $this->assertSame('2026-01-01', (string) $doc->sitemap[0]->lastmod);
        $this->assertSame('', (string) $doc->sitemap[1]->lastmod);
    }
}
