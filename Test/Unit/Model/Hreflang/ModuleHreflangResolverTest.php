<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Hreflang;

use Magento\Framework\ObjectManagerInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Model\Hreflang\ModuleHreflangResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ModuleHreflangResolverTest extends TestCase
{
    private const SOURCE = 'Panth\\Hreflang\\Api\\HreflangResolverInterface';

    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('sitemapUseHreflangModule')->willReturn($enabled);
        return $config;
    }

    public function testDisabledReturnsEmptyWithoutLookup(): void
    {
        $om = $this->createMock(ObjectManagerInterface::class);
        $om->expects($this->never())->method('get');
        $resolver = new ModuleHreflangResolver($om, $this->config(false), $this->createStub(LoggerInterface::class));
        $this->assertSame([], $resolver->getAlternates('product', 1, 1));
    }

    public function testRowsAreFilteredAndTrimmed(): void
    {
        if (!interface_exists(self::SOURCE)) {
            $this->markTestSkipped('Panth_Hreflang module is not installed.');
        }
        $source = $this->createStub(self::SOURCE);
        $source->method('getAlternates')->willReturn([
            ['locale' => ' en-us ', 'url' => ' https://a/en '],
            ['locale' => '', 'url' => 'https://a/x'],
            'junk',
        ]);
        $om = $this->createMock(ObjectManagerInterface::class);
        $om->expects($this->once())->method('get')->with(self::SOURCE)->willReturn($source);
        $resolver = new ModuleHreflangResolver($om, $this->config(true), $this->createStub(LoggerInterface::class));

        $expected = [['locale' => 'en-us', 'url' => 'https://a/en']];
        $this->assertSame($expected, $resolver->getAlternates('product', 1, 1));
        $this->assertSame($expected, $resolver->getAlternates('product', 1, 1), 'source is resolved once');
    }

    public function testSourceExceptionIsLoggedAndSwallowed(): void
    {
        if (!interface_exists(self::SOURCE)) {
            $this->markTestSkipped('Panth_Hreflang module is not installed.');
        }
        $source = $this->createStub(self::SOURCE);
        $source->method('getAlternates')->willThrowException(new \RuntimeException('fail'));
        $om = $this->createStub(ObjectManagerInterface::class);
        $om->method('get')->willReturn($source);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('fail'));
        $resolver = new ModuleHreflangResolver($om, $this->config(true), $logger);
        $this->assertSame([], $resolver->getAlternates('category', 2, 1));
    }

    public function testUnavailableSourceReturnsEmpty(): void
    {
        if (!interface_exists(self::SOURCE)) {
            $om = $this->createMock(ObjectManagerInterface::class);
            $om->expects($this->never())->method('get');
        } else {
            $om = $this->createStub(ObjectManagerInterface::class);
            $om->method('get')->willThrowException(new \RuntimeException('no di'));
        }
        $resolver = new ModuleHreflangResolver($om, $this->config(true), $this->createStub(LoggerInterface::class));
        $this->assertSame([], $resolver->getAlternates('cms', 3, 1));
    }
}
