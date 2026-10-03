<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Panth\XmlSitemap\Api\HreflangResolverInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Model\Sitemap\Contributor\AdditionalLinksContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\CategoryContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\CmsPageContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\DynamicFormContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\HreflangContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\LandingPageContributor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SimpleContributorsTest extends TestCase
{
    use DbStubTrait;

    private function scope(array $values): ScopeConfigInterface
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn(string $p) => $values[$p] ?? null);
        return $scope;
    }

    public function testAdditionalLinksValidatesDeduplicatesAndUsesDefaults(): void
    {
        $contributor = new AdditionalLinksContributor($this->scope([
            'panth_xml_sitemap/additional/additional_links' =>
                "https://shop.test/a\r\n  https://shop.test/a \nftp://shop.test/f\nnot a url\nhttp://shop.test/b",
            'panth_xml_sitemap/additional/additional_links_changefreq' => 'sometimes',
            'panth_xml_sitemap/additional/additional_links_priority' => '7',
        ]));
        $urls = iterator_to_array($contributor->getUrls(1), false);

        $this->assertSame('additional_links', $contributor->getCode());
        $this->assertSame(['https://shop.test/a', 'http://shop.test/b'], array_column($urls, 'loc'));
        $this->assertSame('monthly', $urls[0]['changefreq']);
        $this->assertSame(0.5, $urls[0]['priority']);
    }

    public function testAdditionalLinksHonoursValidSettings(): void
    {
        $contributor = new AdditionalLinksContributor($this->scope([
            'panth_xml_sitemap/additional/additional_links' => 'https://shop.test/a',
            'panth_xml_sitemap/additional/additional_links_changefreq' => 'daily',
            'panth_xml_sitemap/additional/additional_links_priority' => '0.86',
        ]));
        $urls = iterator_to_array($contributor->getUrls(1), false);
        $this->assertSame('daily', $urls[0]['changefreq']);
        $this->assertSame(0.9, $urls[0]['priority']);
    }

    public function testAdditionalLinksEmptyConfigYieldsNothing(): void
    {
        $this->assertSame([], iterator_to_array((new AdditionalLinksContributor($this->scope([])))->getUrls(1), false));
    }

    public function testCmsPagesSkipHomeAndNoRouteAndApplyConfig(): void
    {
        $conn = $this->connection([[
            ['page_id' => 1, 'identifier' => 'home', 'update_time' => null],
            ['page_id' => 2, 'identifier' => 'no-route'],
            ['page_id' => 3, 'identifier' => '/about-us', 'update_time' => '2026-01-02 03:04:05'],
            ['page_id' => 4, 'identifier' => ''],
            ['page_id' => 5, 'identifier' => 'faq', 'update_time' => 'garbage-date'],
        ]], ['panth_seo_resolved' => true]);
        $contributor = new CmsPageContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope(['web/default/cms_home_page' => 'home'])
        );

        $urls = iterator_to_array($contributor->getUrls(1, [
            'changefreq' => 'yearly',
            'priority' => '0.3',
            'exclude_noindex' => true,
            'excluded_cms_identifiers' => "privacy, terms\nlegal",
        ]), false);

        $this->assertSame('cms_page', $contributor->getCode());
        $this->assertCount(2, $urls);
        $this->assertSame('https://shop.test/about-us', $urls[0]['loc']);
        $this->assertSame('yearly', $urls[0]['changefreq']);
        $this->assertSame(0.3, $urls[0]['priority']);
        $this->assertSame('cms', $urls[0]['entity_type']);
        $this->assertSame(3, $urls[0]['entity_id']);
        $this->assertStringStartsWith('2026-01-02T03:04:05', $urls[0]['lastmod']);
        $this->assertArrayNotHasKey('lastmod', $urls[1]);
        $this->assertContains('p.identifier NOT IN (?)', $this->whereLog);
        $this->assertContains('seo.robots IS NULL OR seo.robots NOT LIKE ?', $this->whereLog);
    }

    public function testCmsDefaultsWithoutExclusions(): void
    {
        $conn = $this->connection([[['page_id' => 3, 'identifier' => 'about']]]);
        $contributor = new CmsPageContributor($this->resource($conn), $this->storeManager(), $this->scope([]));
        $urls = iterator_to_array($contributor->getUrls(1), false);

        $this->assertSame('monthly', $urls[0]['changefreq']);
        $this->assertSame(0.5, $urls[0]['priority']);
        $this->assertNotContains('p.identifier NOT IN (?)', $this->whereLog);
        $this->assertNotContains('seo.robots IS NULL OR seo.robots NOT LIKE ?', $this->whereLog);
    }

    public function testCategoriesFilterByRootActiveAndSitemapAttributes(): void
    {
        $conn = $this->connection(
            [[
                ['request_path' => 'women.html', 'entity_id' => 10, 'category_updated_at' => '2026-02-01 00:00:00'],
                ['request_path' => '', 'entity_id' => 11],
                ['request_path' => '/men.html', 'entity_id' => 12, 'category_updated_at' => ''],
            ]],
            ['panth_seo_resolved' => false],
            [],
            [],
            ["'is_active'" => 46, "'in_xml_sitemap'" => 0]
        );
        $contributor = new CategoryContributor($this->resource($conn), $this->storeManager(1, 7));
        $urls = iterator_to_array($contributor->getUrls(1, ['exclude_noindex' => true]), false);

        $this->assertSame('category', $contributor->getCode());
        $this->assertSame(['https://shop.test/women.html', 'https://shop.test/men.html'], array_column($urls, 'loc'));
        $this->assertSame(0.7, $urls[0]['priority']);
        $this->assertSame('weekly', $urls[0]['changefreq']);
        $this->assertSame(10, $urls[0]['entity_id']);
        $this->assertArrayHasKey('lastmod', $urls[0]);
        $this->assertArrayNotHasKey('lastmod', $urls[1]);
        $this->assertContains('cce.path LIKE ?', $this->whereLog);
        $this->assertContains('COALESCE(act_store.value, act_admin.value) = ?', $this->whereLog);
        $this->assertNotContains('COALESCE(sm_store.value, sm_admin.value, 1) = ?', $this->whereLog);
        $this->assertNotContains('seo.robots IS NULL OR seo.robots NOT LIKE ?', $this->whereLog, 'table missing');
    }

    public function testDynamicFormsBuildPageUrls(): void
    {
        $conn = $this->connection(
            [[
                ['url_key' => ' contact ', 'updated_at' => '2026-03-03 10:00:00'],
                ['url_key' => ''],
            ]],
            [],
            ['panth_dynamic_form' => ['url_key' => [], 'updated_at' => [], 'is_active' => [], 'form_type' => [], 'store_id' => []]]
        );
        $contributor = new DynamicFormContributor($this->resource($conn), $this->storeManager(), $this->createStub(LoggerInterface::class));
        $urls = iterator_to_array($contributor->getUrls(1, ['priority' => 0.9]), false);

        $this->assertSame('dynamic_form', $contributor->getCode());
        $this->assertCount(1, $urls);
        $this->assertSame('https://shop.test/pages/contact', $urls[0]['loc']);
        $this->assertSame(0.9, $urls[0]['priority']);
        $this->assertSame('monthly', $urls[0]['changefreq']);
        $this->assertArrayHasKey('lastmod', $urls[0]);
        $this->assertContains('is_active = ?', $this->whereLog);
        $this->assertContains('form_type IN (?)', $this->whereLog);
        $this->assertContains('store_id IN (?)', $this->whereLog);
    }

    public function testDynamicFormsMissingTableOrDbFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('db unavailable'));

        $missing = new DynamicFormContributor(
            $this->resource($this->connection([], ['panth_dynamic_form' => false])),
            $this->storeManager(),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame([], iterator_to_array($missing->getUrls(1), false));

        $down = new DynamicFormContributor($this->resource(null, new \RuntimeException('down')), $this->storeManager(), $logger);
        $this->assertSame([], iterator_to_array($down->getUrls(1), false));
    }

    public function testDynamicFormQueryFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('dynamic-form rows failed'));
        $contributor = new DynamicFormContributor($this->resource($this->connection([])), $this->storeManager(), $logger);
        $this->assertSame([], iterator_to_array($contributor->getUrls(1), false));
    }

    public function testHreflangContributorEmitsOnlyEntitiesWithAlternates(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('sitemapIncludeHreflang')->willReturn(true);
        $resolver = $this->createStub(HreflangResolverInterface::class);
        $resolver->method('getAlternates')->willReturnCallback(
            static fn(string $type, int $id) => $id === 1
                ? [['locale' => 'de-de', 'url' => 'https://shop.test/de/p'], ['locale' => '', 'url' => 'x']]
                : []
        );
        $conn = $this->connection([
            [['entity_id' => 1, 'request_path' => 'p.html'], ['entity_id' => 2, 'request_path' => 'q.html'], ['entity_id' => 0, 'request_path' => 'z']],
            [['entity_id' => 1, 'request_path' => 'cat.html']],
        ]);
        $contributor = new HreflangContributor($this->resource($conn), $this->storeManager(), $resolver, $config);
        $urls = iterator_to_array($contributor->getUrls(1), false);

        $this->assertSame('hreflang', $contributor->getCode());
        $this->assertCount(2, $urls);
        $this->assertSame('https://shop.test/p.html', $urls[0]['loc']);
        $this->assertSame(0.8, $urls[0]['priority']);
        $this->assertSame([['locale' => 'de-de', 'url' => 'https://shop.test/de/p']], $urls[0]['hreflang']);
        $this->assertSame(0.7, $urls[1]['priority']);
    }

    public function testHreflangContributorDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('sitemapIncludeHreflang')->willReturn(false);
        $resource = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $contributor = new HreflangContributor(
            $resource,
            $this->storeManager(),
            $this->createStub(HreflangResolverInterface::class),
            $config
        );
        $this->assertSame([], iterator_to_array($contributor->getUrls(1), false));
    }

    public function testLandingPageContributor(): void
    {
        $om = $this->createStub(ObjectManagerInterface::class);
        $contributor = new LandingPageContributor($om, $this->storeManager());
        $this->assertSame('landing_page', $contributor->getCode());

        if (!class_exists('Panth\\StructuredData\\Model\\LandingPage\\LandingPageDetector')) {
            $this->assertSame([], iterator_to_array($contributor->getUrls(1), false));
            return;
        }

        $detector = new class {
            public function getLandingPages(int $storeId): array
            {
                return [
                    ['identifier' => 'sale', 'update_time' => '2026-01-01 00:00:00'],
                    ['identifier' => 'no-route'],
                    ['identifier' => ''],
                    ['identifier' => '/new'],
                ];
            }
        };
        $om->method('get')->willReturn($detector);
        $urls = iterator_to_array($contributor->getUrls(1), false);
        $this->assertSame(['https://shop.test/sale', 'https://shop.test/new'], array_column($urls, 'loc'));
        $this->assertSame(0.8, $urls[0]['priority']);
        $this->assertArrayHasKey('lastmod', $urls[0]);
        $this->assertArrayNotHasKey('lastmod', $urls[1]);
    }
}
