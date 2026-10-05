<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
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
        $config->method('isSitemapEnabled')->willReturn($overrides['enabled'] ?? true);
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
            $options['hreflang'] ?? $this->createStub(HreflangResolverInterface::class),
            $contributors
        );
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

    public function testBuildFromProfileUsesConfiguredFilenameForIndexPartsAndStylesheet(): void
    {
        $dir = $this->pub;
        foreach ([
            'sitemap-1-1.xml' => 'core',
            'sitemap_index.xml' => 'core index',
            'sitemap-products-1.xml' => 'other profile',
            'luma-products-7.xml' => 'stale',
            'luma-notes.xml' => 'foreign',
        ] as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $store->method('getCode')->willReturn('luma');
        $store->method('isUrlSecure')->willReturn(true);
        $store->method('getBaseUrl')->willReturnCallback(
            static fn($type = UrlInterface::URL_TYPE_LINK) => $type === UrlInterface::URL_TYPE_WEB
                ? 'https://luma.test/'
                : 'https://luma.test/luma/'
        );

        $stats = $this->builder([
            $this->contributor('product', [['loc' => 'https://luma.test/p']]),
            $this->contributor('cms_page', [['loc' => 'https://luma.test/about']]),
        ], ['store' => $store, 'config' => $this->config(['xsl' => true])])->buildFromProfile([
            'store_id' => 2,
            'output_path' => '/',
            'sitemap_filename' => 'luma.xml',
            'custom_links' => '/contact',
        ]);

        $this->assertSame([
            $dir . '/luma-products-1.xml',
            $dir . '/luma-cms-1.xml',
            $dir . '/luma-custom-1.xml',
            $dir . '/luma.xml',
        ], $stats['files']);
        $this->assertFileExists($dir . '/luma-style.xsl');
        $this->assertFileDoesNotExist($dir . '/luma-products-7.xml');
        foreach (['sitemap-1-1.xml', 'sitemap_index.xml', 'sitemap-products-1.xml', 'luma-notes.xml'] as $kept) {
            $this->assertFileExists($dir . '/' . $kept);
        }

        $indexXml = (string) file_get_contents($dir . '/luma.xml');
        $this->assertStringContainsString('luma-style.xsl', $indexXml);
        $this->assertStringNotContainsString('panth-sitemap', $indexXml);
        $index = simplexml_load_string($indexXml);
        $this->assertSame('https://luma.test/luma-products-1.xml', (string) $index->sitemap[0]->loc);
        $this->assertSame('https://luma.test/luma-custom-1.xml', (string) $index->sitemap[2]->loc);
        $custom = simplexml_load_file($dir . '/luma-custom-1.xml');
        $this->assertSame('https://luma.test/luma/contact', (string) $custom->url[0]->loc);
    }

    public function testBuildFromProfileFallsBackToConfigFilenameForInvalidStoredName(): void
    {
        $stats = $this->builder([$this->contributor('category', [['loc' => 'https://shop.test/c']])])->buildFromProfile([
            'store_id' => 1,
            'output_path' => 'seo/{store_code}',
            'sitemap_filename' => '../../app/etc/env.xml',
        ]);
        $this->assertSame([
            $this->pub . '/seo/default/sitemap-categories-1.xml',
            $this->pub . '/seo/default/sitemap.xml',
        ], $stats['files']);
    }

    public function testDisabledStoreBuildWritesNothing(): void
    {
        $product = $this->contributor('product', [['loc' => 'https://shop.test/p1']]);
        $files = $this->builder([$product], ['config' => $this->config(['enabled' => false])])->build(1);
        $this->assertSame([], $files);
        $this->assertSame([], $product->configs);
        $this->assertDirectoryDoesNotExist($this->pub . '/xmlsitemap');
    }

    public function testDisabledStoreProfileRemovesItsFilesAndWritesNothing(): void
    {
        $names = ['sitemap.xml', 'sitemap-products-1.xml', 'sitemap-style.xsl', 'sitemap_luma.xml', 'robots.txt'];
        foreach ($names as $name) {
            file_put_contents($this->pub . '/' . $name, 'x');
        }
        $product = $this->contributor('product', [['loc' => 'https://shop.test/p1']]);
        $delta = $this->createMock(DeltaTracker::class);
        $delta->expects($this->never())->method('mark');

        $stats = $this->builder([$product], ['config' => $this->config(['enabled' => false]), 'delta' => $delta])
            ->buildFromProfile(['store_id' => 1, 'output_path' => '/', 'sitemap_filename' => 'sitemap.xml']);

        $this->assertTrue($stats['disabled']);
        $this->assertSame(0, $stats['url_count']);
        $this->assertSame(0, $stats['file_count']);
        $this->assertSame([], $stats['files']);
        $this->assertSame(3, $stats['removed']);
        $this->assertSame([], $product->configs);
        $this->assertFileDoesNotExist($this->pub . '/sitemap.xml');
        $this->assertFileDoesNotExist($this->pub . '/sitemap-products-1.xml');
        $this->assertFileDoesNotExist($this->pub . '/sitemap-style.xsl');
        $this->assertFileExists($this->pub . '/sitemap_luma.xml');
        $this->assertFileExists($this->pub . '/robots.txt');
    }

    public function testRemoveProfileFilesDeletesOnlyThatProfilesFiles(): void
    {
        mkdir($this->pub . '/seo', 0775, true);
        $files = [
            'hyva.xml', 'hyva-products-1.xml', 'hyva-products-2.xml.gz', 'hyva-faqs-1.xml', 'hyva-style.xsl',
            'luma.xml', 'luma-products-1.xml', 'hyva-notes.xml', 'sitemap.xml',
        ];
        foreach ($files as $name) {
            file_put_contents($this->pub . '/seo/' . $name, 'x');
        }

        $removed = $this->builder([$this->contributor('faq', [])])->removeProfileFiles([
            'store_id' => 1,
            'output_path' => 'seo',
            'sitemap_filename' => 'hyva.xml',
        ]);

        $this->assertSame(5, $removed);
        $left = array_values(array_diff(scandir($this->pub . '/seo'), ['.', '..']));
        sort($left);
        $this->assertSame(['hyva-notes.xml', 'luma-products-1.xml', 'luma.xml', 'sitemap.xml'], $left);
    }

    public function testRemoveProfileFilesWithoutFolderRemovesNothing(): void
    {
        $this->assertSame(0, $this->builder([])->removeProfileFiles([
            'store_id' => 1,
            'output_path' => 'missing/{store_code}',
            'sitemap_filename' => 'sitemap.xml',
        ]));
    }

    public function testResolveProfileFilename(): void
    {
        $builder = $this->builder([]);
        $this->assertSame('sitemap_en.xml', $builder->resolveProfileFilename(['sitemap_filename' => 'sitemap_en.xml'], 1));
        $this->assertSame('sitemap.xml', $builder->resolveProfileFilename(['sitemap_filename' => ''], 1));
        $this->assertSame('sitemap.xml', $builder->resolveProfileFilename([], 1));
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
