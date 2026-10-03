<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Controller;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\BuilderInterface;
use Panth\XmlSitemap\Controller\Sitemap\Index;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use PHPUnit\Framework\TestCase;

class SitemapIndexTest extends TestCase
{
    private array $result = [];
    private array $cache = [];

    private function controller(BuilderInterface $builder, int $page = 0, ?Filesystem $fs = null, bool $cacheThrows = false): Index
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->result['status'] = $code;
            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->result['headers'][$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($body) use ($raw) {
            $this->result['body'] = $body;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $sm = $this->createStub(StoreManagerInterface::class);
        $sm->method('getStore')->willReturn($store);

        $cache = $this->createStub(CacheInterface::class);
        if ($cacheThrows) {
            $cache->method('load')->willThrowException(new \RuntimeException('redis down'));
        } else {
            $cache->method('load')->willReturnCallback(fn(string $k) => $this->cache[$k] ?? false);
            $cache->method('save')->willReturnCallback(function ($data, $key) {
                $this->cache[$key] = $data;
                return true;
            });
        }

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($page);

        if ($fs === null) {
            $dir = $this->createStub(ReadInterface::class);
            $dir->method('isFile')->willReturn(false);
            $fs = $this->createStub(Filesystem::class);
            $fs->method('getDirectoryRead')->willReturn($dir);
        }

        return new Index($rawFactory, $sm, $fs, $builder, $cache, $request);
    }

    public function testServesCachedPage(): void
    {
        $this->cache['panth_xml_sitemap_dynamic_1_0'] = '<cached/>';
        $builder = $this->createMock(Builder::class);
        $builder->expects($this->never())->method('buildPublicSitemap');
        $this->controller($builder)->execute();
        $this->assertSame(200, $this->result['status']);
        $this->assertSame('<cached/>', $this->result['body']);
        $this->assertSame('application/xml; charset=utf-8', $this->result['headers']['Content-Type']);
    }

    public function testSinglePageSitemapIsCachedAsPageZeroAndOne(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('buildPublicSitemap')->willReturn(['index' => null, 'pages' => [1 => '<urlset/>']]);
        $this->controller($builder)->execute();

        $this->assertSame('<urlset/>', $this->result['body']);
        $this->assertSame('<urlset/>', $this->cache['panth_xml_sitemap_dynamic_1_0']);
        $this->assertSame('<urlset/>', $this->cache['panth_xml_sitemap_dynamic_1_1']);
    }

    public function testMultiPageServesIndexAndRequestedPage(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('buildPublicSitemap')->willReturn(['index' => '<index/>', 'pages' => [1 => '<p1/>', 2 => '<p2/>']]);
        $this->controller($builder, 2)->execute();
        $this->assertSame('<p2/>', $this->result['body']);
        $this->assertSame('<index/>', $this->cache['panth_xml_sitemap_dynamic_1_0']);
    }

    public function testUnknownPageReturns404(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('buildPublicSitemap')->willReturn(['index' => '<index/>', 'pages' => [1 => '<p1/>', 2 => '<p2/>']]);
        $this->controller($builder, 7)->execute();
        $this->assertSame(404, $this->result['status']);
        $this->assertStringContainsString('<urlset', $this->result['body']);
    }

    public function testPageBeyondCachedSetReturns404WithoutRebuilding(): void
    {
        $this->cache['panth_xml_sitemap_dynamic_1_0'] = '<index/>';
        $builder = $this->createMock(Builder::class);
        $builder->expects($this->never())->method('buildPublicSitemap');
        $this->controller($builder, 5)->execute();
        $this->assertSame(404, $this->result['status']);
    }

    public function testGenericBuilderUsesBuildForStore(): void
    {
        $builder = $this->createStub(BuilderInterface::class);
        $builder->method('buildForStore')->willReturn('<generic/>');
        $this->controller($builder)->execute();
        $this->assertSame('<generic/>', $this->result['body']);
        $this->assertSame('<generic/>', $this->cache['panth_xml_sitemap_dynamic_1_0']);
    }

    public function testGenericBuilderRejectsDeepPages(): void
    {
        $builder = $this->createMock(BuilderInterface::class);
        $builder->expects($this->never())->method('buildForStore');
        $this->controller($builder, 2)->execute();
        $this->assertSame(404, $this->result['status']);
    }

    public function testFallsBackToMagentoSitemapFile(): void
    {
        $dir = $this->createStub(ReadInterface::class);
        $dir->method('isFile')->willReturnCallback(static fn(string $p) => $p === 'sitemap/sitemap.xml');
        $dir->method('readFile')->willReturn('<core/>');
        $fs = $this->createStub(Filesystem::class);
        $fs->method('getDirectoryRead')->willReturn($dir);

        $builder = $this->createStub(BuilderInterface::class);
        $builder->method('buildForStore')->willReturn('');
        $this->controller($builder, 0, $fs)->execute();
        $this->assertSame(200, $this->result['status']);
        $this->assertSame('<core/>', $this->result['body']);
    }

    public function testBuilderErrorFallsBackToEmptyUrlset(): void
    {
        $this->controller($this->createStub(Builder::class), 0, null, true)->execute();
        $this->assertSame(200, $this->result['status']);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>', $this->result['body']);
    }
}
