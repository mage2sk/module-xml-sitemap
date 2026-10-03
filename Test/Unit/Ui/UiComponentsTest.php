<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Ui;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\System\Store as SystemStore;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use Panth\XmlSitemap\Model\ResourceModel\Profile\Collection as ProfileCollection;
use Panth\XmlSitemap\Model\ResourceModel\Profile\CollectionFactory;
use Panth\XmlSitemap\Ui\Component\Form\DataProvider\ProfileFormDataProvider;
use Panth\XmlSitemap\Ui\Component\Form\StoreViewSource;
use Panth\XmlSitemap\Ui\Component\Listing\Column\ProfileActions;
use Panth\XmlSitemap\Ui\Component\Listing\Column\SitemapUrl;
use Panth\XmlSitemap\Ui\Component\Listing\Column\Store as StoreColumn;
use Panth\XmlSitemap\Ui\Component\Listing\LikeFulltextFilter;
use PHPUnit\Framework\TestCase;

class UiComponentsTest extends TestCase
{
    private function storeManager(bool $throws = false): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $sm = $this->createStub(StoreManagerInterface::class);
        if ($throws) {
            $sm->method('getStore')->willThrowException(new \RuntimeException('gone'));
        } else {
            $sm->method('getStore')->willReturn($store);
        }
        $sm->method('getDefaultStoreView')->willReturn($store);
        return $sm;
    }

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getSitemapIndexFilename')->willReturn('sitemap.xml');
        return $config;
    }

    private function sitemapUrlColumn(bool $throws = false): SitemapUrl
    {
        return new SitemapUrl(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->storeManager($throws),
            new PathResolver(),
            $this->config(),
            [],
            ['name' => 'sitemap_url']
        );
    }

    public function testSitemapUrlColumnRendersLinksAndPlaceholders(): void
    {
        $result = $this->sitemapUrlColumn()->prepareDataSource(['data' => ['items' => [
            ['file_count' => 0],
            ['file_count' => 3, 'store_id' => 0, 'output_path' => 'maps/{store_code}/'],
        ]]]);
        $items = $result['data']['items'];
        $this->assertStringContainsString('-</span>', $items[0]['sitemap_url']);
        $this->assertStringContainsString('href="https://shop.test/maps/default/sitemap.xml"', $items[1]['sitemap_url']);
        $this->assertStringContainsString('rel="noopener"', $items[1]['sitemap_url']);
    }

    public function testSitemapUrlColumnHandlesErrorsAndMissingItems(): void
    {
        $this->assertSame(['data' => []], $this->sitemapUrlColumn()->prepareDataSource(['data' => []]));
        $result = $this->sitemapUrlColumn(true)->prepareDataSource(['data' => ['items' => [['file_count' => 1, 'store_id' => 2]]]]);
        $this->assertSame('', $result['data']['items'][0]['sitemap_url']);
    }

    public function testProfileActionsBuildsLinks(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn(string $path, array $p) => $path . '/id/' . $p['id']);
        $column = new ProfileActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $url,
            $this->storeManager(),
            new PathResolver(),
            $this->config(),
            [],
            ['name' => 'actions']
        );
        $result = $column->prepareDataSource(['data' => ['items' => [
            ['profile_id' => 4, 'file_count' => 2, 'profile_store_id' => 1],
            ['profile_id' => 5, 'file_count' => 0],
            ['name' => 'no id'],
        ]]]);
        [$first, $second, $third] = $result['data']['items'];

        $this->assertSame('https://shop.test/xmlsitemap/default/sitemap.xml', $first['actions']['view']['href']);
        $this->assertSame('panth_xml_sitemap/profile/edit/id/4', $first['actions']['edit']['href']);
        $this->assertTrue($first['actions']['generate']['post']);
        $this->assertSame('panth_xml_sitemap/profile/delete/id/4', $first['actions']['delete']['href']);
        $this->assertArrayNotHasKey('view', $second['actions']);
        $this->assertArrayNotHasKey('actions', $third);
    }

    public function testStoreColumnNormalisesStoreIds(): void
    {
        $systemStore = $this->createStub(SystemStore::class);
        $systemStore->method('getStoresStructure')->willReturn([]);
        $column = new StoreColumn(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $systemStore,
            $this->createStub(Escaper::class),
            [],
            ['name' => 'store_view']
        );
        $result = $column->prepareDataSource(['data' => ['items' => [
            ['store_id' => '3'],
            ['store_id' => [2, 4]],
            ['store_id' => '5', 'profile_store_id' => 9],
        ]]]);
        $items = $result['data']['items'];
        $this->assertSame(3, $items[0]['profile_store_id']);
        $this->assertSame([3], $items[0]['store_id']);
        $this->assertSame(2, $items[1]['profile_store_id']);
        $this->assertSame(9, $items[2]['profile_store_id']);
        $this->assertSame([5], $items[2]['store_id']);
    }

    public function testStoreViewSourceSkipsAdmin(): void
    {
        $make = function (int $id, string $name, string $code) {
            $s = $this->createStub(Store::class);
            $s->method('getId')->willReturn($id);
            $s->method('getName')->willReturn($name);
            $s->method('getCode')->willReturn($code);
            return $s;
        };
        $sm = $this->createStub(StoreManagerInterface::class);
        $sm->method('getStores')->willReturn([$make(0, 'Admin', 'admin'), $make(1, 'English', 'en')]);
        $this->assertSame([['value' => 1, 'label' => 'English (en)']], (new StoreViewSource($sm))->toOptionArray());
    }

    public function testLikeFulltextFilterBuildsEscapedOrCondition(): void
    {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('quoteIdentifier')->willReturnCallback(static fn($c) => '`' . $c . '`');
        $conn->method('quoteInto')->willReturnCallback(static fn($text, $v) => str_replace('?', "'" . $v . "'", $text));
        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('where')->with("`name` LIKE '%50\\%\\_off%' OR `output_path` LIKE '%50\\%\\_off%'");
        $collection = $this->createStub(ProfileCollection::class);
        $collection->method('getConnection')->willReturn($conn);
        $collection->method('getSelect')->willReturn($select);

        $filter = new Filter(['value' => ' 50%_off ']);
        (new LikeFulltextFilter(['name', 42, 'output_path']))->apply($collection, $filter);
    }

    public function testLikeFulltextFilterIgnoresEmptyValuesAndPlainCollections(): void
    {
        $collection = $this->createMock(ProfileCollection::class);
        $collection->expects($this->never())->method('getSelect');
        (new LikeFulltextFilter(['name']))->apply($collection, new Filter(['value' => '  ']));
        (new LikeFulltextFilter([]))->apply($collection, new Filter(['value' => 'x']));
        (new LikeFulltextFilter(['name']))->apply($collection, new Filter(['value' => ['array']]));

        $plain = $this->createMock(Collection::class);
        $plain->expects($this->never())->method('getItems');
        (new LikeFulltextFilter(['name']))->apply($plain, new Filter(['value' => 'x']));
    }

    private function dataProvider(array $items): ProfileFormDataProvider
    {
        $collection = $this->createStub(ProfileCollection::class);
        $collection->method('getItems')->willReturn($items);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return new ProfileFormDataProvider('form', 'profile_id', 'id', $factory);
    }

    public function testFormDataSplitsEntityTypes(): void
    {
        $provider = $this->dataProvider([new DataObject(['id' => 3, 'name' => 'Main', 'entity_types' => 'product,cms'])]);
        $data = $provider->getData();
        $this->assertSame(['product', 'cms'], $data[3]['entity_types']);
        $this->assertSame('Main', $data[3]['name']);
        $this->assertSame($data, $provider->getData(), 'memoised');
    }

    public function testFormDataDefaultsForNewProfile(): void
    {
        $data = $this->dataProvider([])->getData();
        $this->assertArrayHasKey('', $data);
        $this->assertSame(['product', 'category', 'cms'], $data['']['entity_types']);
        $this->assertSame('0 2 * * *', $data['']['cron_schedule']);
        $this->assertSame('xmlsitemap/{store_code}/', $data['']['output_path']);
    }
}
