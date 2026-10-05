<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap\Contributor;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Model\Sitemap\Contributor\ImageContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\ProductContributor;
use Panth\XmlSitemap\Model\Sitemap\Contributor\VideoContributor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogMediaContributorsTest extends TestCase
{
    use DbStubTrait;

    private function videoContributor(?\Magento\Framework\App\ResourceConnection $resource = null, bool $enabled = true): VideoContributor
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn($enabled);
        return new VideoContributor(
            $resource ?? $this->resource($this->connection()),
            $this->storeManager(),
            $scope
        );
    }

    #[DataProvider('videoUrlProvider')]
    public function testResolveVideoLocation(string $url, array $expected): void
    {
        $this->assertSame($expected, $this->videoContributor()->resolveVideoLocation($url));
    }

    public static function videoUrlProvider(): array
    {
        return [
            'mp4 file'        => ['https://cdn.test/v/clip.MP4', ['content_loc' => 'https://cdn.test/v/clip.MP4']],
            'youtube watch'   => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', ['player_loc' => 'https://www.youtube.com/embed/dQw4w9WgXcQ']],
            'youtu.be'        => ['https://youtu.be/dQw4w9WgXcQ', ['player_loc' => 'https://www.youtube.com/embed/dQw4w9WgXcQ']],
            'youtube shorts'  => ['https://m.youtube.com/shorts/abcdef123', ['player_loc' => 'https://www.youtube.com/embed/abcdef123']],
            'nocookie embed'  => ['https://www.youtube-nocookie.com/embed/abcdef123', ['player_loc' => 'https://www.youtube.com/embed/abcdef123']],
            'bad youtube id'  => ['https://www.youtube.com/watch?v=<script>', ['player_loc' => 'https://www.youtube.com/watch?v=<script>']],
            'vimeo'           => ['https://vimeo.com/123456', ['player_loc' => 'https://player.vimeo.com/video/123456']],
            'vimeo no id'     => ['https://vimeo.com/channels', ['player_loc' => 'https://vimeo.com/channels']],
            'no host'         => [' /relative/video ', ['player_loc' => '/relative/video']],
            'other host'      => ['https://example.test/watch/9', ['player_loc' => 'https://example.test/watch/9']],
        ];
    }

    public function testVideoDisabledByConfigOrProfile(): void
    {
        $resource = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $this->assertSame([], iterator_to_array($this->videoContributor($resource, false)->getUrls(1), false));
        $this->assertSame(
            [],
            iterator_to_array($this->videoContributor($resource, true)->getUrls(1, ['include_video_sitemap' => false]), false)
        );
    }

    public function testVideoRowsAreGroupedPerProduct(): void
    {
        $conn = $this->connection([[
            ['request_path' => 'a.html', 'video_url' => 'https://youtu.be/abcdef123', 'video_title' => '', 'video_label' => 'Label A', 'video_description' => 'D', 'video_thumbnail' => '/t/a.jpg'],
            ['request_path' => 'a.html', 'video_url' => 'https://cdn.test/a.mp4', 'video_title' => 'Second', 'video_thumbnail' => ''],
            ['request_path' => '', 'video_url' => 'https://cdn.test/x.mp4'],
            ['request_path' => 'b.html', 'video_url' => 'https://vimeo.com/42', 'video_title' => 'B', 'video_thumbnail' => 'b.jpg'],
        ]], [], [], [], ["'status'" => 96]);
        $contributor = $this->videoContributor($this->resource($conn));
        $urls = iterator_to_array($contributor->getUrls(1), false);

        $this->assertSame('video', $contributor->getCode());
        $this->assertCount(2, $urls);
        $this->assertSame('https://shop.test/a.html', $urls[0]['loc']);
        $this->assertCount(2, $urls[0]['video']);
        $this->assertSame('Label A', $urls[0]['video'][0]['title']);
        $this->assertSame('https://shop.test/media/catalog/product/t/a.jpg', $urls[0]['video'][0]['thumbnail_loc']);
        $this->assertSame('https://www.youtube.com/embed/abcdef123', $urls[0]['video'][0]['player_loc']);
        $this->assertSame('', $urls[0]['video'][1]['thumbnail_loc']);
        $this->assertSame('https://cdn.test/a.mp4', $urls[0]['video'][1]['content_loc']);
        $this->assertSame('https://player.vimeo.com/video/42', $urls[1]['video'][0]['player_loc']);
    }

    private function productContributor(array $fetchAll, array $config = [], array $fetchOne = []): ProductContributor
    {
        $cfg = $this->createStub(Config::class);
        $cfg->method('isSitemapHomepageOptimization')->willReturn($config['homepage'] ?? false);
        $cfg->method('sitemapExcludeOutOfStock')->willReturn(false);
        $cfg->method('sitemapExcludeNoindex')->willReturn(false);
        $cfg->method('sitemapIncludeImages')->willReturn($config['images'] ?? false);
        $cfg->method('getSitemapProductImageSource')->willReturn($config['image_source'] ?? 'base_image');
        $conn = $this->connection([], [], [], $fetchAll, $fetchOne ?: ["'status'" => 96, "'visibility'" => 99]);
        return new ProductContributor($this->resource($conn), $this->storeManager(), $cfg, $this->createStub(MediaConfig::class));
    }

    public function testProductRowsBecomeUrls(): void
    {
        $contributor = $this->productContributor([[
            ['url_rewrite_id' => 1, 'request_path' => 'shoe.html', 'entity_id' => 5, 'product_updated_at' => '2026-01-01 00:00:00'],
            ['url_rewrite_id' => 2, 'request_path' => '', 'entity_id' => 6],
            ['url_rewrite_id' => 3, 'request_path' => '/bag.html', 'entity_id' => 7, 'product_updated_at' => ''],
        ]]);
        $urls = iterator_to_array($contributor->getUrls(1, ['changefreq' => 'daily', 'priority' => '0.4']), false);

        $this->assertSame('product', $contributor->getCode());
        $this->assertSame(['https://shop.test/shoe.html', 'https://shop.test/bag.html'], array_column($urls, 'loc'));
        $this->assertSame('daily', $urls[0]['changefreq']);
        $this->assertSame(0.4, $urls[0]['priority']);
        $this->assertSame(5, $urls[0]['entity_id']);
        $this->assertSame('product', $urls[0]['entity_type']);
        $this->assertArrayHasKey('lastmod', $urls[0]);
        $this->assertArrayNotHasKey('lastmod', $urls[1]);
        $this->assertArrayNotHasKey('images', $urls[0]);
    }

    public function testProductHomepageOptimisationAndImages(): void
    {
        $contributor = $this->productContributor(
            [
                [['attribute_code' => 'image', 'attribute_id' => 87], ['attribute_code' => 'thumbnail', 'attribute_id' => 89]],
                [
                    ['url_rewrite_id' => 1, 'request_path' => 'home', 'entity_id' => 1, 'product_image' => 'a/b.jpg'],
                    ['url_rewrite_id' => 2, 'request_path' => 'p.html', 'entity_id' => 2, 'product_image' => 'no_selection'],
                ],
            ],
            ['homepage' => true, 'images' => true, 'image_source' => 'thumbnail']
        );
        $urls = iterator_to_array($contributor->getUrls(1, ['priority_homepage' => 0.9]), false);

        $this->assertSame('https://shop.test/', $urls[0]['loc']);
        $this->assertSame(0.9, $urls[0]['priority']);
        $this->assertSame('daily', $urls[0]['changefreq']);
        $this->assertSame('https://shop.test/home', $urls[1]['loc']);
        $this->assertSame('daily', $urls[1]['changefreq']);
        $this->assertSame(0.9, $urls[1]['priority']);
        $this->assertSame([['loc' => 'https://shop.test/media/catalog/product/a/b.jpg']], $urls[1]['images']);
        $this->assertArrayNotHasKey('images', $urls[2]);
    }

    public function testProductPaginatesUntilShortBatch(): void
    {
        $batch = [];
        for ($i = 1; $i <= 2000; $i++) {
            $batch[] = ['url_rewrite_id' => $i, 'request_path' => 'p' . $i . '.html', 'entity_id' => $i];
        }
        $contributor = $this->productContributor([$batch, [['url_rewrite_id' => 2001, 'request_path' => 'last.html', 'entity_id' => 1]]]);
        $urls = iterator_to_array($contributor->getUrls(1), false);
        $this->assertCount(2001, $urls);
        $this->assertSame('https://shop.test/last.html', end($urls)['loc']);
    }

    public function testImageContributorEmitsGalleryImages(): void
    {
        $conn = $this->connection([[
            ['entity_id' => 5, 'request_path' => 'shoe.html'],
            ['entity_id' => 0, 'request_path' => 'skip.html'],
        ]]);
        $gallery = [];
        for ($i = 1; $i <= 7; $i++) {
            $gallery[] = new DataObject(['url' => 'https://shop.test/media/' . $i . '.jpg', 'label' => $i === 1 ? 'Front' : '']);
        }
        array_splice($gallery, 1, 0, [new DataObject(['url' => ''])]);
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(5);
        $product->method('getName')->willReturn('Shoe');
        $product->method('getMediaGalleryImages')->willReturn($gallery);
        $stranger = $this->createStub(Product::class);
        $stranger->method('getId')->willReturn(99);

        $collection = $this->createStub(Collection::class);
        foreach (['setStoreId', 'addAttributeToSelect', 'addFieldToFilter', 'addAttributeToFilter', 'setVisibility', 'addWebsiteFilter', 'addMediaGalleryData'] as $m) {
            $collection->method($m)->willReturnSelf();
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product, $stranger]));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $contributor = new ImageContributor($this->resource($conn), $this->storeManager(), $factory, $this->createStub(ImageHelper::class));
        $urls = iterator_to_array($contributor->getUrls(1), false);

        $this->assertSame('image', $contributor->getCode());
        $this->assertCount(1, $urls);
        $this->assertSame('https://shop.test/shoe.html', $urls[0]['loc']);
        $this->assertCount(5, $urls[0]['images'], 'capped at five images');
        $this->assertSame('Front', $urls[0]['images'][0]['caption']);
        $this->assertSame('Shoe', $urls[0]['images'][1]['title'], 'falls back to product name');
    }
}
