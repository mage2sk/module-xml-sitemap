<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Panth\XmlSitemap\Observer\RemoveDisabledSitemaps;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RemoveDisabledSitemapsTest extends TestCase
{
    private function config(array $enabledStores): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isSitemapEnabled')->willReturnCallback(
            static fn(?int $storeId = null) => in_array($storeId, $enabledStores, true)
        );
        return $config;
    }

    private function observer(Builder $builder, Config $config, ?ManagerInterface $messages = null, ?LoggerInterface $logger = null): RemoveDisabledSitemaps
    {
        return new RemoveDisabledSitemaps(
            $builder,
            $config,
            $messages ?? $this->createStub(ManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testRemovesFilesOnlyForProfilesOfDisabledStores(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([
            ['profile_id' => 1, 'store_id' => 1],
            ['profile_id' => 2, 'store_id' => 2],
        ]);
        $builder->expects($this->once())->method('removeProfileFiles')
            ->with(['profile_id' => 2, 'store_id' => 2])->willReturn(4);
        $builder->expects($this->once())->method('updateProfileStats')
            ->with(2, ['url_count' => 0, 'file_count' => 0, 'generation_time' => 0]);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addNoticeMessage')
            ->with($this->callback(static fn($m) => str_contains((string) $m, '4 sitemap file(s)')));

        $this->observer($builder, $this->config([1]), $messages)->execute(new Observer());
    }

    public function testEnabledStoresKeepTheirFilesAndShowNoNotice(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([['profile_id' => 1, 'store_id' => 1]]);
        $builder->expects($this->never())->method('removeProfileFiles');
        $builder->expects($this->never())->method('updateProfileStats');
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->never())->method('addNoticeMessage');

        $this->observer($builder, $this->config([1]), $messages)->execute(new Observer());
    }

    public function testNoNoticeWhenNothingWasOnDisk(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([['profile_id' => 3, 'store_id' => 2]]);
        $builder->method('removeProfileFiles')->willReturn(0);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->never())->method('addNoticeMessage');

        $this->observer($builder, $this->config([]), $messages)->execute(new Observer());
    }

    public function testFailuresAreLoggedAndDoNotStopOtherProfiles(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([
            ['profile_id' => 1, 'store_id' => 1],
            ['profile_id' => 2, 'store_id' => 2],
        ]);
        $builder->method('removeProfileFiles')->willReturnCallback(
            static function (array $profile): int {
                if ((int) $profile['profile_id'] === 1) {
                    throw new \RuntimeException('locked');
                }
                return 2;
            }
        );
        $builder->expects($this->once())->method('updateProfileStats')->with(2, $this->anything());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('profile 1: locked'));

        $this->observer($builder, $this->config([]), null, $logger)->execute(new Observer());
    }

    public function testProfileLoadFailureIsLogged(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willThrowException(new \RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('db down'));

        $this->observer($builder, $this->config([]), null, $logger)->execute(new Observer());
    }
}
