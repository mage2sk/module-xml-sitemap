<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Panth\XmlSitemap\Model\Sitemap\Contributor\BlogContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\FaqContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\TestimonialContributor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContentContributorsTest extends TestCase
{
    use DbStubTrait;

    private function scope(array $values): ScopeConfigInterface
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn(string $p) => $values[$p] ?? null);
        return $scope;
    }

    private function modules(array $enabled): ModuleManager
    {
        $mm = $this->createStub(ModuleManager::class);
        $mm->method('isEnabled')->willReturnCallback(static fn(string $m) => in_array($m, $enabled, true));
        return $mm;
    }

    public function testTestimonialsEmitLandingCategoriesAndItems(): void
    {
        $conn = $this->connection(
            [
                [['url_key' => 'happy', 'updated_at' => '2026-01-01 00:00:00'], ['url_key' => ' ']],
                [['url_key' => 'jane'], ['url_key' => 'bad-date', 'updated_at' => 'nope']],
            ],
            [],
            ['panth_testimonial' => ['status' => [], 'store_id' => [], 'updated_at' => []]]
        );
        $contributor = new TestimonialContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope(['panth_testimonials/general/route' => '/reviews/']),
            $this->createStub(LoggerInterface::class)
        );
        $urls = iterator_to_array($contributor->getUrls(1, ['priority' => 0.95]), false);

        $this->assertSame('testimonial', $contributor->getCode());
        $this->assertSame([
            'https://shop.test/reviews',
            'https://shop.test/reviews/category/happy',
            'https://shop.test/reviews/jane',
            'https://shop.test/reviews/bad-date',
        ], array_column($urls, 'loc'));
        $this->assertSame(1.0, $urls[0]['priority'], 'landing priority is capped at 1.0');
        $this->assertSame(0.95, $urls[2]['priority']);
        $this->assertArrayHasKey('lastmod', $urls[1]);
        $this->assertNull($urls[3]['lastmod']);
        $this->assertContains('status = ?', $this->whereLog);
    }

    public function testTestimonialsNoTablesOrDbDown(): void
    {
        $none = new TestimonialContributor(
            $this->resource($this->connection([], ['panth_testimonial' => false, 'panth_testimonial_category' => false])),
            $this->storeManager(),
            $this->scope([]),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame([], iterator_to_array($none->getUrls(1), false));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('db unavailable'));
        $down = new TestimonialContributor($this->resource(null, new \RuntimeException('x')), $this->storeManager(), $this->scope([]), $logger);
        $this->assertSame([], iterator_to_array($down->getUrls(1), false));
    }

    public function testTestimonialQueryFailuresAreLoggedButLandingRemains(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('info');
        $contributor = new TestimonialContributor($this->resource($this->connection([])), $this->storeManager(), $this->scope([]), $logger);
        $urls = iterator_to_array($contributor->getUrls(1), false);
        $this->assertSame(['https://shop.test/testimonials'], array_column($urls, 'loc'));
        $this->assertEqualsWithDelta(0.6, $urls[0]['priority'], 0.0001);
    }

    public function testFaqEmitsLandingCategoriesAndItemsWithStoreJoin(): void
    {
        $conn = $this->connection(
            [
                [['url_key' => 'shipping', 'updated_at' => '2026-01-01 00:00:00']],
                [['item_id' => 1, 'url_key' => 'returns'], ['item_id' => 2, 'url_key' => '']],
            ],
            [],
            ['panth_faq_item' => ['is_active' => []]]
        );
        $contributor = new FaqContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope([]),
            $this->createStub(LoggerInterface::class)
        );
        $urls = iterator_to_array($contributor->getUrls(1), false);

        $this->assertSame('faq', $contributor->getCode());
        $this->assertSame([
            'https://shop.test/faq',
            'https://shop.test/faq/category/shipping',
            'https://shop.test/faq/item/returns',
        ], array_column($urls, 'loc'));
        $this->assertSame('monthly', $urls[2]['changefreq']);
        $this->assertContains('i.is_active = ?', $this->whereLog);
    }

    public function testFaqOnlyItemsTable(): void
    {
        $conn = $this->connection(
            [[['item_id' => 1, 'url_key' => 'a']]],
            ['panth_faq_category' => false, 'panth_faq_item_store' => false]
        );
        $contributor = new FaqContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope(['panth_faq/general/faq_route' => 'help']),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame(
            ['https://shop.test/help', 'https://shop.test/help/item/a'],
            array_column(iterator_to_array($contributor->getUrls(1), false), 'loc')
        );
    }

    public function testFaqWithoutTablesYieldsNothing(): void
    {
        $contributor = new FaqContributor(
            $this->resource($this->connection([], ['panth_faq_item' => false, 'panth_faq_category' => false])),
            $this->storeManager(),
            $this->scope([]),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame([], iterator_to_array($contributor->getUrls(1), false));
    }

    public function testBlogPrefersPanthBlogTables(): void
    {
        $conn = $this->connection([], [], [], [
            [
                ['url_key' => '/hello/', 'updated_at' => '2026-01-05 00:00:00', 'published_at' => null],
                ['url_key' => 'draftless', 'updated_at' => '', 'published_at' => '2026-01-01 00:00:00'],
                ['url_key' => '', 'updated_at' => null],
            ],
            [['url_key' => 'news', 'updated_at' => null]],
            [['url_key' => 'tips', 'updated_at' => '2026-01-01']],
            [['url_key' => 'john']],
        ]);
        $contributor = new BlogContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope(['panth_blog/general/route_frontname' => '/journal/']),
            $this->modules([]),
            $this->createStub(LoggerInterface::class)
        );
        $urls = iterator_to_array($contributor->getUrls(1, ['priority' => 0.9]), false);

        $this->assertSame('blog', $contributor->getCode());
        $this->assertSame([
            'https://shop.test/journal/hello',
            'https://shop.test/journal/draftless',
            'https://shop.test/journal/category/news',
            'https://shop.test/journal/tag/tips',
            'https://shop.test/journal/author/john',
            'https://shop.test/journal',
        ], array_column($urls, 'loc'));
        $this->assertSame(0.9, $urls[0]['priority']);
        $this->assertSame(date('c', strtotime('2026-01-01 00:00:00')), $urls[1]['lastmod']);
        $this->assertSame('', $urls[2]['lastmod']);
        $this->assertSame(0.4, $urls[3]['priority']);
        $this->assertSame(0.7, $urls[5]['priority']);
    }

    public function testBlogMagefanPermalinks(): void
    {
        $conn = $this->connection(
            [[['post_id' => 1, 'identifier' => '/first/', 'update_time' => '2026-01-01'], ['post_id' => 2, 'identifier' => '']]],
            ['panth_blog_post' => false],
            ['magefan_blog_post' => ['identifier' => [], 'update_time' => [], 'is_active' => [], 'publish_time' => []]]
        );
        $contributor = new BlogContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope(['mfblog/permalink/route' => 'news', 'mfblog/permalink/post_sufix' => '.html']),
            $this->modules(['Magefan_Blog']),
            $this->createStub(LoggerInterface::class)
        );
        $urls = iterator_to_array($contributor->getUrls(1), false);

        $this->assertSame(['https://shop.test/news/post/first.html'], array_column($urls, 'loc'));
        $this->assertSame(0.6, $urls[0]['priority']);
        $this->assertContains('p.is_active = ?', $this->whereLog);
    }

    public function testBlogMagefanShortTypeAddsTrailingSlash(): void
    {
        $conn = $this->connection(
            [[['post_id' => 1, 'identifier' => 'first']]],
            ['panth_blog_post' => false, 'magefan_blog_post_store' => false],
            ['magefan_blog_post' => ['identifier' => []]]
        );
        $contributor = new BlogContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope(['mfblog/permalink/type' => 'short']),
            $this->modules(['Magefan_Blog']),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame(
            ['https://shop.test/blog/first/'],
            array_column(iterator_to_array($contributor->getUrls(1), false), 'loc')
        );
    }

    public function testBlogMageplazaWithSuffix(): void
    {
        $conn = $this->connection(
            [[['url_key' => 'post-a', 'updated_at' => '2026-01-01']]],
            ['panth_blog_post' => false],
            ['mageplaza_blog_post' => ['url_key' => [], 'updated_at' => [], 'enabled' => [], 'store_ids' => []]]
        );
        $contributor = new BlogContributor(
            $this->resource($conn),
            $this->storeManager(),
            $this->scope(['blog/general/url_prefix' => 'stories', 'blog/display/url_suffix' => 'html']),
            $this->modules(['Mageplaza_Blog']),
            $this->createStub(LoggerInterface::class)
        );
        $urls = iterator_to_array($contributor->getUrls(1), false);
        $this->assertSame(['https://shop.test/stories/post/post-a.html'], array_column($urls, 'loc'));
        $this->assertContains('enabled = ?', $this->whereLog);
        $this->assertContains('FIND_IN_SET(0, store_ids) OR FIND_IN_SET(?, store_ids)', $this->whereLog);
    }

    public function testBlogWithNoSupportedEngineYieldsNothing(): void
    {
        $contributor = new BlogContributor(
            $this->resource($this->connection([], ['panth_blog_post' => false])),
            $this->storeManager(),
            $this->scope([]),
            $this->modules([]),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame([], iterator_to_array($contributor->getUrls(1), false));
    }

    public function testBlogDbUnavailableIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('blog contributor - db unavailable'));
        $contributor = new BlogContributor(
            $this->resource(null, new \RuntimeException('x')),
            $this->storeManager(),
            $this->scope([]),
            $this->modules([]),
            $logger
        );
        $this->assertSame([], iterator_to_array($contributor->getUrls(1), false));
    }
}
