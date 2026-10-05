<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Panth\XmlSitemap\Model\Sitemap\UrlElementWriter;
use PHPUnit\Framework\TestCase;

class UrlElementWriterTest extends TestCase
{
    private UrlElementWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new UrlElementWriter();
    }

    private function render(array $url, array $options): array
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $this->writer->writeUrlsetStart($w, $options);
        $written = $this->writer->write($w, $url, $options);
        $w->endElement();
        return [$written, $w->outputMemory()];
    }

    public function testUrlsetDeclaresOptionalNamespaces(): void
    {
        [, $xml] = $this->render(['loc' => 'https://a/'], ['include_hreflang' => true, 'include_video' => true]);
        $this->assertStringContainsString('xmlns:xhtml="' . UrlElementWriter::NS_XHTML . '"', $xml);
        $this->assertStringContainsString('xmlns:video="' . UrlElementWriter::NS_VIDEO . '"', $xml);

        [, $plain] = $this->render(['loc' => 'https://a/'], []);
        $this->assertStringNotContainsString('xmlns:xhtml', $plain);
        $this->assertStringNotContainsString('xmlns:video', $plain);
        $this->assertStringContainsString('xmlns:image', $plain);
    }

    public function testEmptyLocIsSkipped(): void
    {
        [$written, $xml] = $this->render(['loc' => ''], []);
        $this->assertFalse($written);
        $this->assertStringNotContainsString('<url>', $xml);
    }

    public function testChangefreqAndPriorityOnlyWhenEnabledAndValid(): void
    {
        $url = ['loc' => 'https://a/p', 'lastmod' => '2026-01-01', 'changefreq' => ' Daily ', 'priority' => '0.75'];
        [$written, $xml] = $this->render($url, ['include_changefreq_priority' => true]);
        $this->assertTrue($written);
        $this->assertStringContainsString('<lastmod>2026-01-01</lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);

        [, $off] = $this->render($url, []);
        $this->assertStringNotContainsString('<changefreq>', $off);
        $this->assertStringNotContainsString('<priority>', $off);

        [, $bad] = $this->render(
            ['loc' => 'https://a/p', 'changefreq' => 'sometimes', 'priority' => '1.5'],
            ['include_changefreq_priority' => true]
        );
        $this->assertStringNotContainsString('<changefreq>', $bad);
        $this->assertStringNotContainsString('<priority>', $bad);
    }

    public function testImagesSkipInvalidEntries(): void
    {
        $url = ['loc' => 'https://a/p', 'images' => [
            ['loc' => 'https://a/i.jpg', 'caption' => 'Cap', 'title' => 'T'],
            ['caption' => 'no loc'],
            'not-an-array',
        ]];
        [, $xml] = $this->render($url, []);
        $this->assertSame(1, substr_count($xml, '<image:image>'));
        $this->assertStringContainsString('<image:caption>Cap</image:caption>', $xml);
        $this->assertStringContainsString('<image:title>T</image:title>', $xml);
    }

    public function testHreflangLinksRequireOptionAndCompleteEntries(): void
    {
        $url = ['loc' => 'https://a/p', 'hreflang' => [
            ['locale' => 'en-us', 'url' => 'https://a/en'],
            ['locale' => 'fr-fr'],
        ]];
        [, $xml] = $this->render($url, ['include_hreflang' => true]);
        $this->assertSame(1, substr_count($xml, '<xhtml:link'));
        $this->assertStringContainsString('hreflang="en-us" href="https://a/en"', $xml);

        [, $off] = $this->render($url, []);
        $this->assertStringNotContainsString('<xhtml:link', $off);
    }

    public function testVideoRequiresLocationThumbnailAndTitle(): void
    {
        $url = ['loc' => 'https://a/p', 'video' => [
            ['player_loc' => 'https://yt/1', 'thumbnail_loc' => 'https://t/1.jpg', 'title' => 'Demo'],
            ['content_loc' => 'https://v/2.mp4', 'thumbnail_loc' => '', 'title' => 'NoThumb'],
            ['thumbnail_loc' => 'https://t/3.jpg', 'title' => 'NoLoc'],
            'junk',
        ]];
        [, $xml] = $this->render($url, ['include_video' => true]);
        $this->assertSame(1, substr_count($xml, '<video:video>'));
        $this->assertStringContainsString('<video:description>Demo</video:description>', $xml);
        $this->assertStringContainsString('<video:player_loc>https://yt/1</video:player_loc>', $xml);
        $this->assertStringNotContainsString('<video:content_loc>', $xml);
    }
}
