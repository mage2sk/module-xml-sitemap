<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Model\Sitemap\SitemapValidator;
use PHPUnit\Framework\TestCase;

class SitemapValidatorTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'smv');
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }

    private function validator(array $baseUrls, bool $throw = false): SitemapValidator
    {
        $sm = $this->createStub(StoreManagerInterface::class);
        if ($throw) {
            $sm->method('getStores')->willThrowException(new \RuntimeException('db down'));
        } else {
            $stores = [];
            foreach ($baseUrls as $url) {
                $store = $this->createStub(\Magento\Store\Model\Store::class);
                $store->method('getBaseUrl')->willReturn($url);
                $stores[] = $store;
            }
            $sm->method('getStores')->willReturn($stores);
        }
        return new SitemapValidator($sm);
    }

    private function urlset(array $locs): string
    {
        $body = '';
        foreach ($locs as $loc) {
            $body .= '<url><loc>' . $loc . '</loc></url>';
        }
        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . $body . '</urlset>';
    }

    public function testMissingFile(): void
    {
        $errors = $this->validator([])->validate('/nonexistent/sitemap.xml');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('does not exist', $errors[0]);
    }

    public function testInvalidXmlReportsParserErrors(): void
    {
        $errors = $this->validator([])->validate($this->file('<urlset><url></urlset>'));
        $this->assertStringContainsString('not valid XML', $errors[0]);
        $this->assertGreaterThan(1, count($errors));
        $this->assertStringStartsWith('XML error (line', $errors[1]);
    }

    public function testValidSitemapMatchingBaseUrlHasNoErrors(): void
    {
        $path = $this->file($this->urlset(['https://shop.test/a', 'https://shop.test/b']));
        $this->assertSame([], $this->validator(['https://shop.test/'])->validate($path));
    }

    public function testForeignAndEmptyLocsAreReported(): void
    {
        $path = $this->file($this->urlset(['https://evil.test/a', ' ', 'https://shop.test/ok']));
        $errors = $this->validator(['https://shop.test/'])->validate($path);
        $this->assertSame([
            'URL does not match any configured base URL: https://evil.test/a',
            'Empty <loc> element found.',
        ], $errors);
    }

    public function testBaseUrlCheckIsSkippedWhenStoresUnavailable(): void
    {
        $path = $this->file($this->urlset(['https://evil.test/a']));
        $this->assertSame([], $this->validator([], true)->validate($path));
    }
}
