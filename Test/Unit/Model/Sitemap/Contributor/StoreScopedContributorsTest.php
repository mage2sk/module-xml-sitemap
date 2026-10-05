<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use Panth\XmlSitemap\Model\Sitemap\Contributor\BlogContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\FaqContributor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StoreScopedContributorsTest extends TestCase
{
    use DbStubTrait;

    /** @var list<string> */
    private array $calls = [];

    private function recordingSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $calls = &$this->calls;
        foreach (['from', 'join', 'joinLeft', 'group', 'columns', 'where'] as $method) {
            $select->method($method)->willReturnCallback(
                static function (...$args) use ($select, $method, &$calls) {
                    $calls[] = $method . ' ' . json_encode(array_map(
                        static fn($a) => $a instanceof \Zend_Db_Expr ? (string) $a : $a,
                        is_array($args[0] ?? null) ? $args[0] : $args
                    )) . (str_starts_with($method, 'join') ? ' ON ' . $args[1] : '');
                    return $select;
                }
            );
        }
        return $select;
    }

    private function recordingConnection(array $tables, array $describe, array $queryRows, array $fetchAll): Mysql
    {
        $conn = $this->createStub(Mysql::class);
        $conn->method('select')->willReturnCallback(fn() => $this->recordingSelect());
        $conn->method('isTableExists')->willReturnCallback(static fn(string $t) => $tables[$t] ?? false);
        $conn->method('describeTable')->willReturnCallback(static fn(string $t) => $describe[$t] ?? []);
        $statements = array_map(fn(array $rows) => $this->statement($rows), $queryRows);
        $conn->method('query')->willReturnCallback(static function () use (&$statements) {
            return array_shift($statements);
        });
        $conn->method('fetchAll')->willReturnOnConsecutiveCalls(...($fetchAll ?: [[]]));
        return $conn;
    }

    private function resourceFor(Mysql $conn): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function modulesOn(string $module): ModuleManager
    {
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn(string $m) => $m === $module);
        return $modules;
    }

    private function joined(string $needle): bool
    {
        foreach ($this->calls as $call) {
            if (str_starts_with($call, 'join') && str_contains($call, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function called(string $prefix): bool
    {
        foreach ($this->calls as $call) {
            if (str_starts_with($call, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function testPanthBlogPostsAndCategoriesAreLimitedToTheStoreView(): void
    {
        $conn = $this->recordingConnection(
            [
                'panth_blog_post' => true,
                'panth_blog_post_store' => true,
                'panth_blog_category' => true,
                'panth_blog_category_store' => true,
            ],
            [],
            [],
            [[['url_key' => 'luma-post', 'updated_at' => null, 'published_at' => null]], [['url_key' => 'corner']]]
        );
        $blog = new BlogContributor(
            $this->resourceFor($conn),
            $this->storeManager(),
            $this->createStub(ScopeConfigInterface::class),
            $this->modulesOn('Panth_Blog'),
            $this->createStub(LoggerInterface::class)
        );

        $locs = array_column(iterator_to_array($blog->getUrls(2), false), 'loc');

        $this->assertSame([
            'https://shop.test/blog/luma-post',
            'https://shop.test/blog/category/corner',
            'https://shop.test/blog',
        ], $locs);
        $this->assertTrue($this->joined('s.post_id = p.post_id AND s.store_id IN (0, 2)'));
        $this->assertTrue($this->joined('s.category_id = t.category_id AND s.store_id IN (0, 2)'));
        $this->assertTrue($this->called('where ["p.status = ?","published"'));
    }

    public function testPanthBlogWithoutStoreTablesDoesNotJoin(): void
    {
        $conn = $this->recordingConnection(['panth_blog_post' => true], [], [], [[['url_key' => 'a']]]);
        $blog = new BlogContributor(
            $this->resourceFor($conn),
            $this->storeManager(),
            $this->createStub(ScopeConfigInterface::class),
            $this->modulesOn('Panth_Blog'),
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame(
            ['https://shop.test/blog/a', 'https://shop.test/blog'],
            array_column(iterator_to_array($blog->getUrls(1), false), 'loc')
        );
        $this->assertFalse($this->joined('store_id'));
    }

    public function testFaqUsesStoreViewUrlKeyAndActiveOverrides(): void
    {
        $conn = $this->recordingConnection(
            [
                'panth_faq_item' => true,
                'panth_faq_item_store' => true,
                'panth_faq_item_value' => true,
                'panth_faq_category' => true,
                'panth_faq_category_store' => true,
                'panth_faq_category_value' => true,
            ],
            [
                'panth_faq_item' => ['is_active' => [], 'updated_at' => []],
                'panth_faq_item_value' => ['url_key' => [], 'is_active' => []],
                'panth_faq_category_value' => ['url_key' => [], 'is_active' => []],
            ],
            [
                [['category_id' => 9, 'url_key' => 'product-care']],
                [['item_id' => 16, 'url_key' => 'luma-return-item']],
            ],
            []
        );
        $faq = new FaqContributor(
            $this->resourceFor($conn),
            $this->storeManager(),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->modulesOn('Panth_Faq')
        );

        $locs = array_column(iterator_to_array($faq->getUrls(2), false), 'loc');

        $this->assertSame([
            'https://shop.test/faq',
            'https://shop.test/faq/category/product-care',
            'https://shop.test/faq/item/luma-return-item',
        ], $locs);
        $this->assertTrue($this->joined('v.item_id = i.item_id AND v.store_id = 2'));
        $this->assertTrue($this->joined('s.item_id = i.item_id AND s.store_id IN (0, 2)'));
        $this->assertTrue($this->joined('v.category_id = c.category_id AND v.store_id = 2'));
        $this->assertTrue($this->joined('s.category_id = c.category_id AND s.store_id IN (0, 2)'));
        $this->assertTrue($this->called('where ["COALESCE(v.is_active, i.is_active) = ?",1'));
        $this->assertContains('columns {"url_key":"COALESCE(NULLIF(v.url_key, \'\'), i.url_key)"}', $this->calls);
    }

    public function testFaqWithoutValueTablesKeepsBaseColumns(): void
    {
        $conn = $this->recordingConnection(
            ['panth_faq_item' => true],
            ['panth_faq_item' => ['is_active' => []]],
            [[['item_id' => 1, 'url_key' => 'base']]],
            []
        );
        $faq = new FaqContributor(
            $this->resourceFor($conn),
            $this->storeManager(),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->modulesOn('Panth_Faq')
        );

        $this->assertSame(
            ['https://shop.test/faq', 'https://shop.test/faq/item/base'],
            array_column(iterator_to_array($faq->getUrls(1), false), 'loc')
        );
        $this->assertTrue($this->called('where ["i.is_active = ?",1'));
        $this->assertFalse($this->joined('v.'));
    }
}
