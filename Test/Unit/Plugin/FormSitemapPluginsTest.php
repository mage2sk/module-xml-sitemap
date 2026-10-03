<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Plugin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Plugin\Admin\CategoryFormSitemapPlugin;
use Panth\XmlSitemap\Plugin\Admin\ProductFormSitemapPlugin;
use PHPUnit\Framework\TestCase;

class FormSitemapPluginsTest extends TestCase
{
    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isSitemapEnabled')->willReturn($enabled);
        return $config;
    }

    public function testDisabledLeavesMetaUntouched(): void
    {
        $meta = ['general' => ['x' => 1]];
        $this->assertSame(
            $meta,
            (new ProductFormSitemapPlugin($this->config(false)))->afterGetMeta($this->createStub(ProductDataProvider::class), $meta)
        );
        $this->assertSame(
            $meta,
            (new CategoryFormSitemapPlugin($this->config(false)))->afterGetMeta($this->createStub(CategoryDataProvider::class), $meta)
        );
    }

    public function testProductToggleIsAddedToSeoFieldset(): void
    {
        $result = (new ProductFormSitemapPlugin($this->config(true)))
            ->afterGetMeta($this->createStub(ProductDataProvider::class), ['general' => []]);

        $this->assertArrayHasKey('general', $result);
        $field = $result['search-engine-optimization']['children']['container_exclude_from_sitemap']
            ['children']['exclude_from_sitemap']['arguments']['data']['config'];
        $this->assertSame('in_xml_sitemap', $field['dataScope']);
        $this->assertSame(['true' => '0', 'false' => '1'], $field['valueMap'], 'checked means excluded');
        $this->assertSame('checkbox', $field['formElement']);
    }

    public function testCategoryToggleUsesCategoryFieldsetName(): void
    {
        $result = (new CategoryFormSitemapPlugin($this->config(true)))
            ->afterGetMeta($this->createStub(CategoryDataProvider::class), []);

        $this->assertArrayNotHasKey('search-engine-optimization', $result);
        $field = $result['search_engine_optimization']['children']['container_exclude_from_sitemap']
            ['children']['exclude_from_sitemap']['arguments']['data']['config'];
        $this->assertSame('in_xml_sitemap', $field['dataScope']);
        $this->assertStringContainsString('category', (string) $field['description']);
    }
}
