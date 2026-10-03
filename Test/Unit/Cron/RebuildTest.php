<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Cron;

use Magento\Framework\FlagManager;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Panth\XmlSitemap\Cron\Rebuild;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Panth\XmlSitemap\Model\Sitemap\ProfileSchedule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RebuildTest extends TestCase
{
    private function flags(mixed $lastRun): FlagManager
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturn($lastRun);
        return $flags;
    }

    public function testDueProfilesAreBuiltAndStatsSaved(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->expects($this->once())->method('loadActiveProfiles')->with(null, true)->willReturn([
            ['profile_id' => 1, 'name' => 'Due', 'cron_schedule' => '0 2 * * *'],
            ['profile_id' => 2, 'name' => 'NotDue', 'cron_schedule' => '0 5 * * *'],
        ]);
        $stats = ['url_count' => 10, 'file_count' => 1, 'generation_time' => 0.5, 'files' => []];
        $builder->expects($this->once())->method('buildFromProfile')
            ->with($this->callback(static fn(array $p) => $p['profile_id'] === 1))
            ->willReturn($stats);
        $builder->expects($this->once())->method('updateProfileStats')->with(1, $stats);

        $schedule = $this->createStub(ProfileSchedule::class);
        $schedule->method('isDue')->willReturnCallback(static fn(string $expr) => $expr === '0 2 * * *');
        $schedule->method('normalise')->willReturnArgument(0);

        $collection = $this->createMock(CollectionFactory::class);
        $collection->expects($this->never())->method('create');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('profile "Due" (id 1'));

        (new Rebuild($collection, $builder, $logger, $schedule, $this->flags(time() - 60)))->execute();
    }

    public function testProfileFailureIsLoggedAndOthersContinue(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([
            ['profile_id' => 1, 'name' => 'A'],
            ['profile_id' => 2, 'name' => 'B'],
        ]);
        $builder->method('buildFromProfile')->willThrowException(new \RuntimeException('disk full'));
        $schedule = $this->createStub(ProfileSchedule::class);
        $schedule->method('isDue')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning')->with($this->stringContains('disk full'));

        (new Rebuild(
            $this->createStub(CollectionFactory::class),
            $builder,
            $logger,
            $schedule,
            $this->flags(null)
        ))->execute();
    }

    public function testFallsBackToCoreSitemapsWhenNoProfiles(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([]);
        $schedule = $this->createMock(ProfileSchedule::class);
        $schedule->expects($this->once())->method('isDue')
            ->with(ProfileSchedule::DEFAULT_EXPRESSION, $this->anything(), $this->anything())
            ->willReturn(true);

        $ok = $this->createMock(Sitemap::class);
        $ok->expects($this->once())->method('generateXml');
        $bad = $this->createStub(Sitemap::class);
        $bad->method('generateXml')->willThrowException(new \RuntimeException('nope'));
        $bad->method('getId')->willReturn(9);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn(new \ArrayIterator([$ok, $bad]));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Panth XmlSitemap rebuild failed for 9: nope');

        (new Rebuild($factory, $builder, $logger, $schedule, $this->flags('0')))->execute();
    }

    public function testFutureLastRunIsReplacedByDefaultWindow(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([]);
        $schedule = $this->createMock(ProfileSchedule::class);
        $schedule->expects($this->once())->method('isDue')
            ->with(
                $this->anything(),
                $this->callback(static fn(int $from) => abs((time() - 300) - $from) <= 2),
                $this->anything()
            )
            ->willReturn(false);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->never())->method('create');

        (new Rebuild(
            $factory,
            $builder,
            $this->createStub(LoggerInterface::class),
            $schedule,
            $this->flags(time() + 10000)
        ))->execute();
    }

    public function testFlagSaveFailureAndBuilderErrorAreLogged(): void
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturn(null);
        $flags->method('saveFlag')->willThrowException(new \RuntimeException('flag table'));
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willThrowException(new \RuntimeException('db'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('flag table'));
        $logger->expects($this->once())->method('error')->with('Panth XmlSitemap cron error: db');

        (new Rebuild(
            $this->createStub(CollectionFactory::class),
            $builder,
            $logger,
            $this->createStub(ProfileSchedule::class),
            $flags
        ))->execute();
    }
}
