<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Magento\Framework\App\CacheInterface;
use Panth\XmlSitemap\Model\Sitemap\DeltaTracker;
use PHPUnit\Framework\TestCase;

class DeltaTrackerTest extends TestCase
{
    public function testGetLastRunReturnsNullForMissingOrEmpty(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnOnConsecutiveCalls(false, '', '2026-01-01T00:00:00+00:00');
        $tracker = new DeltaTracker($cache);

        $this->assertNull($tracker->getLastRun(1));
        $this->assertNull($tracker->getLastRun(1));
        $this->assertSame('2026-01-01T00:00:00+00:00', $tracker->getLastRun(1));
    }

    public function testMarkAndClearUseStoreScopedKey(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('save')
            ->with('2026-02-02', 'panth_seo_sitemap_last_run_3', ['panth_seo_sitemap'], 31536000);
        $cache->expects($this->once())->method('remove')->with('panth_seo_sitemap_last_run_3');

        $tracker = new DeltaTracker($cache);
        $tracker->mark(3, '2026-02-02');
        $tracker->clear(3);
    }
}
