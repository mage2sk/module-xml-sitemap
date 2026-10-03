<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Panth\XmlSitemap\Api\HreflangResolverInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Panth\XmlSitemap\Model\Sitemap\DeltaTracker;
use Panth\XmlSitemap\Model\Sitemap\IndexWriter;
use Panth\XmlSitemap\Model\Sitemap\ShardWriter;
use Panth\XmlSitemap\Model\Sitemap\ShardWriterFactory;
use Panth\XmlSitemap\Model\Sitemap\UrlElementWriter;
use Panth\XmlSitemap\Model\Sitemap\XslStylesheet;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BuilderTest extends TestCase
{
    private string $pub;

    protected function setUp(): void
    {
        $this->pub = sys_get_temp_dir() . '/xmlsitemap_builder_' . uniqid('', true);
        mkdir($this->pub, 0775, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->pub, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->pub);
    }

    private function contributor(string $code, array $urls, ?\Throwable $error = null): ContributorInterface
    {
        return new class ($code, $urls, $error) implements ContributorInterface {
            public array $configs = [];

            public function __construct(private string $code, private array $urls, private ?\Throwable $error)
            {
            }

            public function getUrls(int $storeId, array $config = []): \Generator
            {
                $this->configs[] = $config;
                foreach ($this->urls as $url) {
                    yield $url;
                }
                if ($this->error !== null) {
                    throw $this->error;
                }
            }

            public function getCode(): string
            {
                return $this->code;
            }
        };
    }

    private function store(int $id = 1, string $code = 'default'): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $store->method('isUrlSecure')->willReturn(true);
        return $store;
    }

    private function config(array $overrides = []): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getSitemapShardSize')->willReturn($overrides['shard_size'] ?? 45000);
        $config->method('getSitemapIndexFilename')->willReturn('sitemap.xml');
        $config->method('isSitemapXslEnabled')->willReturn($overrides['xsl'] ?? false);
        $config->method('sitemapIncludeHreflang')->willReturn($overrides['hreflang'] ?? false);
        $config->method('sitemapIncludeVideo')->willReturn(false);
        $config->method('sitemapIncludeChangefreqPriority')->willReturn(true);
        $config->method('sitemapGzip')->willReturn(false);
        return $config;
    }

    private function filesystem(): Filesystem
    {
        $pub = $this->pub;
        $dir = $this->createStub(WriteInterface::class);
        $dir->method('create')->willReturnCallback(static function ($path) use ($pub) {
            if (!is_dir($pub . '/' . $path)) {
                mkdir($pub . '/' . $path, 0775, true);
            }
            return true;
        });
        $dir->method('getAbsolutePath')->willReturnCallback(
            static fn($path = null) => $pub . '/' . ($path === null ? '' : $path)
        );
        $fs = $this->createStub(Filesystem::class);
        $fs->method('getDirectoryWrite')->willReturn($dir);
        return $fs;
    }

    private function builder(
        array $contributors,
        array $options = []
    ): Builder {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($options['store'] ?? $this->store());
        $storeManager->method('getDefaultStoreView')->willReturn($options['store'] ?? $this->store());

        $shardFactory = $this->createStub(ShardWriterFactory::class);
        $shardFactory->method('create')->willReturnCallback(
            static fn() => new ShardWriter(new UrlElementWriter())
        );

        $resource = $options['resource'] ?? null;
        if ($resource === null) {
            $resource = $this->createStub(ResourceConnection::class);
            $resource->method('getConnection')->willThrowException(new \RuntimeException('no db'));
        }

        return new Builder(
            $storeManager,
            $this->filesystem(),
            $shardFactory,
            new IndexWriter(),
            $options['delta'] ?? $this->createStub(DeltaTracker::class),
            $options['config'] ?? $this->config(),
            $options['logger'] ?? $this->createStub(LoggerInterface::class),
            new XslStylesheet(),
            $resource,
            new PathResolver(),
            new UrlElementWriter(),
            $options['hreflang'] ?? $this->createStub(HreflangResolverInterface::class),
            $contributors
        );
    }

    private function resourceWithProfile(?array $row, bool $tableExists = true): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $m) {
            $select->method($m)->willReturnSelf();
        }
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn($tableExists);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchRow')->willReturn($row ?? false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    public function testBuildWritesShardsIndexAndMarksDelta(): void
    {
        $dir = $this->pub . '/xmlsitemap/default';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/sitemap-9.xml', 'stale');

        $delta = $this->createMock(DeltaTracker::class);
        $delta->expects($this->once())->method('mark')->with(1, $this->isString());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('"broken" failed: kaput'));

        $builder = $this->builder([
            'not a contributor',
            $this->contributor('product', [
                ['loc' => 'https://shop.test//a'],
                ['loc' => ''],
                'junk',
                ['loc' => 'https://shop.test/b'],
                ['loc' => 'https://shop.test/c'],
            ]),
            $this->contributor('broken', [], new \RuntimeException('kaput')),
        ], ['delta' => $delta, 'logger' => $logger, 'config' => $this->config(['shard_size' => 2, 'xsl' => true])]);

        $files = array_values(iterator_to_array($builder->build(1)));

        $this->assertSame([
            $dir . '/sitemap-1.xml',
            $dir . '/sitemap-2.xml',
            $dir . '/sitemap.xml',
        ], $files);
        $this->assertFileDoesNotExist($dir . '/sitemap-9.xml');
        $this->assertFileExists($dir . '/sitemap-style.xsl');

        $first = simplexml_load_file($dir . '/sitemap-1.xml');
        $this->assertSame('https://shop.test/a', (string) $first->url[0]->loc);
        $this->assertCount(2, $first->url);

        $index = simplexml_load_file($dir . '/sitemap.xml');
        $this->assertCount(2, $index->sitemap);
        $this->assertSame('https://shop.test/xmlsitemap/default/sitemap-2.xml', (string) $index->sitemap[1]->loc);
    }

    public function testBuildWithoutUrlsWritesNoIndex(): void
    {
        $files = $this->builder([$this->contributor('product', [])])->build(1);
        $this->assertSame([], $files);
        $this->assertFileDoesNotExist($this->pub . '/xmlsitemap/default/sitemap.xml');
    }

    public function testBuildAttachesHreflangAlternates(): void
    {
        $resolver = $this->createMock(HreflangResolverInterface::class);
        $resolver->expects($this->once())->method('getAlternates')->with('product', 5, 1)
            ->willReturn([['locale' => 'fr-fr', 'url' => 'https://shop.test/fr/p']]);

        $builder = $this->builder([
            $this->contributor('product', [
                ['loc' => 'https://shop.test/p', 'entity_type' => 'product', 'entity_id' => 5],
                ['loc' => 'https://shop.test/q', 'entity_type' => 'blog', 'entity_id' => 6],
                ['loc' => 'https://shop.test/r', 'entity_type' => 'product', 'entity_id' => 0],
            ]),
        ], ['hreflang' => $resolver, 'config' => $this->config(['hreflang' => true])]);
        $builder->build(1);

        $xml = (string) file_get_contents($this->pub . '/xmlsitemap/default/sitemap-1.xml');
        $this->assertStringContainsString('hreflang="fr-fr" href="https://shop.test/fr/p"', $xml);
        $this->assertSame(1, substr_count($xml, '<xhtml:link'));
    }

    public function testHreflangFailureIsLoggedAndUrlKept(): void
    {
        $resolver = $this->createStub(HreflangResolverInterface::class);
        $resolver->method('getAlternates')->willThrowException(new \RuntimeException('lookup'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('lookup'));

        $builder = $this->builder([
            $this->contributor('category', [['loc' => 'https://shop.test/c', 'entity_type' => 'category', 'entity_id' => 2]]),
        ], ['hreflang' => $resolver, 'logger' => $logger, 'config' => $this->config(['hreflang' => true])]);

        $this->assertCount(2, $builder->build(1));
    }

    public function testPublicSitemapDeduplicatesAndReturnsSinglePage(): void
    {
        $builder = $this->builder([
            $this->contributor('product', [['loc' => 'https://shop.test/a'], ['loc' => 'https://shop.test//a']]),
            $this->contributor('category', [['loc' => 'https://shop.test/c', 'changefreq' => 'daily', 'priority' => 0.7]]),
        ]);

        $parts = $builder->buildPublicSitemap(1);
        $this->assertNull($parts['index']);
        $this->assertCount(1, $parts['pages']);
        $doc = simplexml_load_string($parts['pages'][1]);
        $this->assertCount(2, $doc->url);
        $this->assertSame('daily', (string) $doc->url[1]->changefreq);
        $this->assertSame($parts['pages'][1], $builder->buildForStore(1));
    }

    public function testPublicSitemapEmptyStillReturnsValidUrlset(): void
    {
        $parts = $this->builder([])->buildPublicSitemap(1);
        $this->assertCount(1, $parts['pages']);
        $doc = simplexml_load_string($parts['pages'][1]);
        $this->assertSame('urlset', $doc->getName());
        $this->assertCount(0, $doc->url);
    }

    public function testPublicSitemapPaginatesAt50000Urls(): void
    {
        $urls = (static function () {
            for ($i = 0; $i <= 50000; $i++) {
                yield ['loc' => 'https://shop.test/p' . $i];
            }
        })();
        $contributor = new class ($urls) implements ContributorInterface {
            public function __construct(private \Generator $urls)
            {
            }

            public function getUrls(int $storeId, array $config = []): \Generator
            {
                yield from $this->urls;
            }

            public function getCode(): string
            {
                return 'product';
            }
        };

        $parts = $this->builder([$contributor], ['config' => $this->config(['xsl' => true])])->buildPublicSitemap(1);
        $this->assertCount(2, $parts['pages']);
        $this->assertNotNull($parts['index']);
        $index = simplexml_load_string($parts['index']);
        $this->assertSame('https://shop.test/panth-sitemap.xml?page=2', (string) $index->sitemap[1]->loc);
        $this->assertStringContainsString('xmlsitemap/default/sitemap-style.xsl', $parts['index']);
        $this->assertSame(1, substr_count($parts['pages'][2], '<url>'));
    }

    public function testPublicSitemapHonoursProfileBucketsAndCustomLinks(): void
    {
        $product = $this->contributor('product', [['loc' => 'https://shop.test/p']]);
        $category = $this->contributor('category', [['loc' => 'https://shop.test/c']]);
        $resource = $this->resourceWithProfile([
            'profile_id' => 1,
            'entity_types' => 'category, custom',
            'custom_links' => "/about,daily,0.9\nhttps://other.test/x\n/c",
            'category_priority' => '0.6',
            'include_hreflang' => 0,
        ]);

        $parts = $this->builder([$product, $category], ['resource' => $resource])->buildPublicSitemap(1);
        $xml = $parts['pages'][1];

        $this->assertStringNotContainsString('https://shop.test/p<', $xml);
        $this->assertStringContainsString('<loc>https://shop.test/about</loc>', $xml);
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.9</priority>', $xml);
        $this->assertStringContainsString('<loc>https://other.test/x</loc>', $xml);
        $this->assertSame(1, substr_count($xml, '<loc>https://shop.test/c</loc>'), 'custom link duplicate skipped');
        $this->assertSame([], $product->configs, 'product bucket not allowed');
        $this->assertSame(0.6, $category->configs[0]['priority']);
        $this->assertFalse($category->configs[0]['include_hreflang_tags']);
    }

    public function testBuildFromProfileWritesPerEntityShards(): void
    {
        $store = $this->store(1, 'default');
        $product = $this->contributor('product', [
            ['loc' => 'https://shop.test/p1'],
            ['loc' => 'https://shop.test/p2', 'lastmod' => '2025-01-01'],
        ]);
        $faq = $this->contributor('faq', [['loc' => 'https://shop.test/faq']]);
        $delta = $this->createMock(DeltaTracker::class);
        $delta->expects($this->once())->method('mark')->with(1, $this->isString());

        $stats = $this->builder([$product, $faq], ['store' => $store, 'delta' => $delta])->buildFromProfile([
            'store_id' => 0,
            'output_path' => 'maps/{store_code}',
            'max_urls_per_file' => 1,
            'entity_settings' => json_encode(['product' => ['priority' => 0.9]]),
            'custom_links' => json_encode(['/contact', ['loc' => 'https://shop.test/x', 'changefreq' => 'yearly', 'priority' => 0.2], ['nope']]),
        ]);

        $dir = $this->pub . '/maps/default';
        $this->assertSame(5, $stats['url_count']);
        $this->assertSame(6, $stats['file_count']);
        $this->assertSame([
            $dir . '/sitemap-products-1.xml',
            $dir . '/sitemap-products-2.xml',
            $dir . '/sitemap-faqs-1.xml',
            $dir . '/sitemap-custom-1.xml',
            $dir . '/sitemap-custom-2.xml',
            $dir . '/sitemap.xml',
        ], $stats['files']);
        $this->assertSame(0.9, $product->configs[0]['priority']);

        $p2 = simplexml_load_file($dir . '/sitemap-products-2.xml');
        $this->assertSame('2025-01-01', (string) $p2->url[0]->lastmod);
        $custom2 = simplexml_load_file($dir . '/sitemap-custom-2.xml');
        $this->assertSame('yearly', (string) $custom2->url[0]->changefreq);

        $index = simplexml_load_file($dir . '/sitemap.xml');
        $this->assertSame('https://shop.test/maps/default/sitemap-faqs-1.xml', (string) $index->sitemap[2]->loc);
    }

    public function testBuildFromProfileContributorFailureIsIsolated(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('profile contributor "category" failed'));

        $stats = $this->builder([
            $this->contributor('category', [], new \RuntimeException('x')),
            $this->contributor('cms_page', [['loc' => 'https://shop.test/about']]),
        ], ['logger' => $logger])->buildFromProfile(['store_id' => 1, 'entity_types' => 'category,cms']);

        $this->assertSame(1, $stats['url_count']);
        $this->assertStringEndsWith('/xmlsitemap/default/sitemap.xml', end($stats['files']));
    }

    public function testLoadProfileCreatesMissingTable(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(false);
        $conn->expects($this->once())->method('query')->with($this->stringContains('CREATE TABLE IF NOT EXISTS `panth_seo_sitemap_profile`'));
        $conn->method('select')->willReturn($select);
        $conn->method('fetchRow')->willReturn(['profile_id' => 3]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame(['profile_id' => 3], $this->builder([], ['resource' => $resource])->loadProfile(3));
    }

    public function testLoadActiveProfilesAppliesFilters(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->exactly(3))->method('where')->willReturnCallback(
            function (string $cond) use ($select) {
                $this->assertContains($cond, ['is_active = ?', 'store_id = ?', 'cron_enabled = ?']);
                return $select;
            }
        );
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(true);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchAll')->willReturn([['profile_id' => 1]]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);

        $this->assertSame([['profile_id' => 1]], $this->builder([], ['resource' => $resource])->loadActiveProfiles(2, true));
    }

    public function testUpdateProfileStats(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(true);
        $conn->expects($this->once())->method('update')->with(
            'panth_seo_sitemap_profile',
            $this->callback(static fn(array $d) => $d['url_count'] === 7 && $d['file_count'] === 2
                && $d['generation_time'] === 1.5 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $d['last_generated_at']) === 1),
            ['profile_id = ?' => 4]
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->builder([], ['resource' => $resource])
            ->updateProfileStats(4, ['url_count' => 7, 'file_count' => 2, 'generation_time' => 1.5]);
    }

    public function testUpdateProfileStatsSkipsMissingTable(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(false);
        $conn->expects($this->never())->method('update');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);

        $this->builder([], ['resource' => $resource])->updateProfileStats(4, []);
    }

    public function testFallbackProfileTableMatchesDeclaredSchema(): void
    {
        $sql = '';
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(false);
        $conn->method('query')->willReturnCallback(function (string $q) use (&$sql) {
            $sql = $q;
            return null;
        });
        $conn->method('select')->willReturn($select);
        $conn->method('fetchRow')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->builder([], ['resource' => $resource])->loadProfile(1);

        preg_match_all('/^\\s*`([a-z_]+)`\\s+[A-Z]/m', $sql, $m);
        $schema = simplexml_load_string(
            (string) file_get_contents(dirname(__DIR__, 4) . '/etc/db_schema.xml')
        );
        $declared = [];
        foreach ($schema->xpath('//table[@name="panth_seo_sitemap_profile"]/column') as $column) {
            $declared[] = (string) $column['name'];
        }
        $created = $m[1];
        sort($declared);
        sort($created);

        $this->assertNotEmpty($declared);
        $this->assertSame($declared, $created);
    }
}
