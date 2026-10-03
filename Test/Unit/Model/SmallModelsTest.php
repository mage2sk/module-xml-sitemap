<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Panth\XmlSitemap\Api\BuilderInterface;
use Panth\XmlSitemap\Model\Config\Source\Changefreq;
use Panth\XmlSitemap\Model\Config\Source\ProductImageSource;
use Panth\XmlSitemap\Model\Hreflang\NullHreflangResolver;
use Panth\XmlSitemap\Model\Profile;
use Panth\XmlSitemap\Model\Queue\ShardConsumer;
use Panth\XmlSitemap\Model\Sitemap\XslStylesheet;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SmallModelsTest extends TestCase
{
    private function profile(array $data): Profile
    {
        $resource = $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class);
        return new Profile(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $resource,
            null,
            $data
        );
    }

    public function testProfileDefaults(): void
    {
        $p = $this->profile([]);
        $this->assertNull($p->getProfileId());
        $this->assertSame('0 2 * * *', $p->getCronSchedule());
        $this->assertSame('xmlsitemap/{store_code}/', $p->getOutputPath());
        $this->assertSame(50000, $p->getMaxUrlsPerFile());
        $this->assertSame(0, $p->getUrlCount());
        $this->assertSame(0, $p->getFileCount());
        $this->assertNull($p->getLastGeneratedAt());
        $this->assertNull($p->getGenerationTime());
        $this->assertNull($p->getCustomLinks());
        $this->assertFalse($p->isActive());
        $this->assertFalse($p->isCronEnabled());
    }

    public function testProfileCastsValues(): void
    {
        $p = $this->profile([
            'profile_id' => '7', 'name' => 'Main', 'store_id' => '2', 'entity_types' => 'product,category',
            'is_active' => '1', 'cron_enabled' => '1', 'max_urls_per_file' => '0', 'url_count' => '12',
            'generation_time' => '3', 'last_generated_at' => '2026-01-01 00:00:00', 'custom_links' => 'x',
        ]);
        $this->assertSame(7, $p->getProfileId());
        $this->assertSame('Main', $p->getName());
        $this->assertSame(2, $p->getStoreId());
        $this->assertSame('product,category', $p->getEntityTypes());
        $this->assertTrue($p->isActive());
        $this->assertTrue($p->isCronEnabled());
        $this->assertSame(50000, $p->getMaxUrlsPerFile(), 'zero falls back to default');
        $this->assertSame(12, $p->getUrlCount());
        $this->assertSame(3, $p->getGenerationTime());
        $this->assertSame('2026-01-01 00:00:00', $p->getLastGeneratedAt());
        $this->assertSame('x', $p->getCustomLinks());
    }

    public function testSourceModels(): void
    {
        $freq = array_column((new Changefreq())->toOptionArray(), 'value');
        $this->assertSame(['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], $freq);
        $img = array_column((new ProductImageSource())->toOptionArray(), 'value');
        $this->assertSame(['base_image', 'small_image', 'thumbnail'], $img);
    }

    public function testNullHreflangResolverReturnsNothing(): void
    {
        $this->assertSame([], (new NullHreflangResolver())->getAlternates('product', 1, 1));
    }

    public function testXslStylesheetIsWellFormedXml(): void
    {
        $xsl = (new XslStylesheet())->getStylesheet();
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xsl));
        $this->assertSame('stylesheet', $doc->documentElement->localName);
        $this->assertStringContainsString('sitemap:sitemapindex', $xsl);
    }

    public function testShardConsumerRejectsInvalidMessages(): void
    {
        $builder = $this->createMock(BuilderInterface::class);
        $builder->expects($this->never())->method('build');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');
        $consumer = new ShardConsumer($builder, $logger);
        $consumer->process('not json');
        $consumer->process('{"store_id":0}');
    }

    public function testShardConsumerBuildsAndLogsCount(): void
    {
        $builder = $this->createMock(BuilderInterface::class);
        $builder->expects($this->once())->method('build')->with(4)->willReturn(['a.xml', 'b.xml']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('Panth SEO sitemap shard: built 2 files for store 4');
        (new ShardConsumer($builder, $logger))->process('{"store_id":"4"}');
    }

    public function testShardConsumerLogsBuilderFailure(): void
    {
        $builder = $this->createStub(BuilderInterface::class);
        $builder->method('build')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('boom'), ['store_id' => 5]);
        (new ShardConsumer($builder, $logger))->process('{"store_id":5}');
    }
}
