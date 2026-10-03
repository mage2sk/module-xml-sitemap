<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Panth\XmlSitemap\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values, array $flags = []): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[$path] ?? null
        );
        $scope->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => (bool) ($flags[$path] ?? false)
        );
        return new Config($this->createStub(Context::class), $scope);
    }

    public function testShardSizeDefaultsTo45000(): void
    {
        $this->assertSame(45000, $this->config([])->getSitemapShardSize(1));
    }

    public function testShardSizeIsClampedToRange(): void
    {
        $this->assertSame(1000, $this->config([Config::XML_SITEMAP_SHARD_SIZE => '5'])->getSitemapShardSize());
        $this->assertSame(50000, $this->config([Config::XML_SITEMAP_SHARD_SIZE => '999999'])->getSitemapShardSize());
        $this->assertSame(20000, $this->config([Config::XML_SITEMAP_SHARD_SIZE => '20000'])->getSitemapShardSize());
    }

    public function testFlagsReadThroughScopeConfig(): void
    {
        $config = $this->config([], [
            Config::XML_SITEMAP_ENABLED => true,
            Config::XML_SITEMAP_GZIP => true,
            Config::XML_SITEMAP_INCLUDE_VIDEO => true,
        ]);
        $this->assertTrue($config->isSitemapEnabled(1));
        $this->assertTrue($config->sitemapGzip(1));
        $this->assertTrue($config->sitemapIncludeVideo(1));
        $this->assertFalse($config->sitemapIncludeImages(1));
        $this->assertFalse($config->sitemapIncludeHreflang(1));
        $this->assertFalse($config->sitemapExcludeOutOfStock(1));
        $this->assertFalse($config->sitemapExcludeNoindex(1));
        $this->assertFalse($config->isSitemapXslEnabled(1));
        $this->assertFalse($config->isSitemapHomepageOptimization(1));
        $this->assertFalse($config->sitemapIncludeChangefreqPriority(1));
        $this->assertFalse($config->sitemapUseHreflangModule(1));
    }

    public function testProductImageSourceWhitelist(): void
    {
        $this->assertSame('base_image', $this->config([])->getSitemapProductImageSource());
        $this->assertSame(
            'thumbnail',
            $this->config([Config::XML_SITEMAP_PRODUCT_IMAGE_SOURCE => 'thumbnail'])->getSitemapProductImageSource()
        );
        $this->assertSame(
            'base_image',
            $this->config([Config::XML_SITEMAP_PRODUCT_IMAGE_SOURCE => 'swatch'])->getSitemapProductImageSource()
        );
    }

    public function testAdditionalLinkDefaults(): void
    {
        $config = $this->config([]);
        $this->assertSame('', $config->getSitemapAdditionalLinks());
        $this->assertSame('weekly', $config->getAdditionalLinksChangefreq());
        $this->assertSame('0.5', $config->getAdditionalLinksPriority());
    }

    public function testAdditionalLinkValues(): void
    {
        $config = $this->config([
            Config::XML_SITEMAP_ADDITIONAL => "https://a/x\nhttps://a/y",
            Config::XML_SITEMAP_ADDITIONAL_LINKS_FREQ => 'daily',
            Config::XML_SITEMAP_ADDITIONAL_LINKS_PRIORITY => '0.9',
        ]);
        $this->assertSame("https://a/x\nhttps://a/y", $config->getSitemapAdditionalLinks());
        $this->assertSame('daily', $config->getAdditionalLinksChangefreq());
        $this->assertSame('0.9', $config->getAdditionalLinksPriority());
    }

    #[DataProvider('indexFilenameProvider')]
    public function testIndexFilenameIsSanitised(?string $raw, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->config([Config::XML_SITEMAP_INDEX_FILENAME => $raw])->getSitemapIndexFilename()
        );
    }

    public static function indexFilenameProvider(): array
    {
        return [
            'unset'         => [null, 'sitemap.xml'],
            'valid'         => ['my-sitemap.xml', 'my-sitemap.xml'],
            'trimmed'       => ['  index_1.xml ', 'index_1.xml'],
            'path stripped' => ['../../etc/passwd.xml', 'passwd.xml'],
            'wrong ext'     => ['sitemap.txt', 'sitemap.xml'],
            'leading dot'   => ['.hidden.xml', 'sitemap.xml'],
            'bad chars'     => ['site map.xml', 'sitemap.xml'],
        ];
    }
}
