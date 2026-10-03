<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Block;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Block\Adminhtml\GenericDeleteButton;
use Panth\XmlSitemap\Block\Adminhtml\Profile\Edit\BackButton;
use Panth\XmlSitemap\Block\Adminhtml\Profile\Edit\GenerateButton;
use Panth\XmlSitemap\Block\Adminhtml\Profile\Edit\ViewSitemapButton;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use PHPUnit\Framework\TestCase;

class ButtonsTest extends TestCase
{
    private function request(int $id): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturn($id);
        $request->method('getRouteName')->willReturn('panth_xml_sitemap');
        $request->method('getControllerName')->willReturn('profile');
        return $request;
    }

    private function url(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn(string $path, ?array $p = []) => 'https://admin.test/' . $path . (isset($p['id']) ? '/id/' . $p['id'] : '')
        );
        return $url;
    }

    public function testDeleteButtonHiddenForNewProfile(): void
    {
        $this->assertSame([], (new GenericDeleteButton($this->url(), $this->request(0)))->getButtonData());
    }

    public function testDeleteButtonUsesCurrentRoute(): void
    {
        $data = (new GenericDeleteButton($this->url(), $this->request(7)))->getButtonData();
        $this->assertStringContainsString("'https://admin.test/panth_xml_sitemap/profile/delete/id/7'", $data['on_click']);
        $this->assertSame('delete', $data['class']);
        $this->assertSame(20, $data['sort_order']);
    }

    public function testGenerateButton(): void
    {
        $this->assertSame([], (new GenerateButton($this->url(), $this->request(0)))->getButtonData());
        $data = (new GenerateButton($this->url(), $this->request(3)))->getButtonData();
        $this->assertStringStartsWith('confirmSetLocation(', $data['on_click']);
        $this->assertStringContainsString('panth_xml_sitemap/profile/generate/id/3', $data['on_click']);
    }

    public function testBackButton(): void
    {
        $data = (new BackButton($this->url()))->getButtonData();
        $this->assertSame("location.href = 'https://admin.test/panth_xml_sitemap/profile/index';", $data['on_click']);
    }

    private function viewButton(int $id, mixed $profile): ViewSitemapButton
    {
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturn($profile);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('en');
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $sm = $this->createStub(StoreManagerInterface::class);
        $sm->method('getStore')->willReturn($store);
        $sm->method('getDefaultStoreView')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('getSitemapIndexFilename')->willReturn('index.xml');
        return new ViewSitemapButton($this->url(), $this->request($id), $registry, $sm, new PathResolver(), $config);
    }

    public function testViewSitemapButtonRequiresGeneratedFiles(): void
    {
        $this->assertSame([], $this->viewButton(0, ['file_count' => 3])->getButtonData());
        $this->assertSame([], $this->viewButton(1, null)->getButtonData());
        $this->assertSame([], $this->viewButton(1, ['file_count' => 0])->getButtonData());
    }

    public function testViewSitemapButtonOpensIndexUrl(): void
    {
        $data = $this->viewButton(1, ['file_count' => 2, 'store_id' => 0, 'output_path' => ''])->getButtonData();
        $this->assertSame("window.open('https://shop.test/xmlsitemap/en/index.xml', '_blank')", $data['on_click']);
    }

    private function withApostropheTranslation(callable $fn): mixed
    {
        $previous = \Magento\Framework\Phrase::getRenderer();
        \Magento\Framework\Phrase::setRenderer(new class implements \Magento\Framework\Phrase\RendererInterface {
            public function render(array $source, array $arguments)
            {
                return "It's " . end($source);
            }
        });
        try {
            return $fn();
        } finally {
            \Magento\Framework\Phrase::setRenderer($previous);
        }
    }

    public function testTranslatedConfirmTextIsJsEscaped(): void
    {
        $delete = $this->withApostropheTranslation(
            fn() => (new GenericDeleteButton($this->url(), $this->request(7)))->getButtonData()
        );
        $generate = $this->withApostropheTranslation(
            fn() => (new GenerateButton($this->url(), $this->request(3)))->getButtonData()
        );

        foreach ([$delete['on_click'], $generate['on_click']] as $onClick) {
            $this->assertStringNotContainsString("It's", $onClick);
            $this->assertStringContainsString('It\\u0027s', $onClick);
        }
    }
}
