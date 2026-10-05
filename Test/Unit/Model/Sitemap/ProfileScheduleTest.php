<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\XmlSitemap\Model\Sitemap\ProfileSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileScheduleTest extends TestCase
{
    private ProfileSchedule $schedule;

    protected function setUp(): void
    {
        $tz = $this->createStub(TimezoneInterface::class);
        $tz->method('getConfigTimezone')->willReturn('UTC');
        $dtf = $this->createStub(DateTimeFactory::class);
        $dtf->method('create')->willReturnCallback(
            static fn($time = 'now', $zone = null) => new \DateTime($time, $zone)
        );
        $cron = new Schedule(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class),
            null,
            [],
            $tz,
            $dtf,
            $this->createStub(\Magento\Cron\Model\DeadlockRetrierInterface::class)
        );
        $factory = $this->createMock(ScheduleFactory::class);
        $factory->expects($this->atMost(1))->method('create')->willReturn($cron);
        $this->schedule = new ProfileSchedule($factory);
    }

    #[DataProvider('expressionProvider')]
    public function testIsValid(string $expression, bool $expected): void
    {
        $this->assertSame($expected, $this->schedule->isValid($expression));
    }

    public static function expressionProvider(): array
    {
        return [
            'daily'          => ['0 2 * * *', true],
            'step'           => ['*/15 * * * *', true],
            'list and range' => ['0,30 8-18 * * 1-5', true],
            'empty'          => ['', false],
            'blank'          => ['   ', false],
            'too few parts'  => ['0 2 *', false],
            'minute 60'      => ['60 * * * *', false],
            'hour 24'        => ['0 24 * * *', false],
            'day 0'          => ['0 0 0 * *', false],
            'month 13'       => ['0 0 1 13 *', false],
            'weekday 8'      => ['0 0 * * 8', false],
            'bad step'       => ['*/x * * * *', false],
        ];
    }

    public function testNormaliseFallsBackToDefault(): void
    {
        $this->assertSame('*/5 * * * *', $this->schedule->normalise(' */5 * * * * '));
        $this->assertSame(ProfileSchedule::DEFAULT_EXPRESSION, $this->schedule->normalise('nonsense'));
        $this->assertSame(ProfileSchedule::DEFAULT_EXPRESSION, $this->schedule->normalise(null));
    }

    public function testIsDueWhenMatchingMinuteInsideWindow(): void
    {
        $from = (int) (new \DateTime('2026-03-10 01:55:00', new \DateTimeZone('UTC')))->format('U');
        $to   = (int) (new \DateTime('2026-03-10 02:05:00', new \DateTimeZone('UTC')))->format('U');
        $this->assertTrue($this->schedule->isDue('0 2 * * *', $from, $to));
    }

    public function testIsNotDueWhenWindowMissesSchedule(): void
    {
        $from = (int) (new \DateTime('2026-03-10 03:00:00', new \DateTimeZone('UTC')))->format('U');
        $to   = (int) (new \DateTime('2026-03-10 03:30:00', new \DateTimeZone('UTC')))->format('U');
        $this->assertFalse($this->schedule->isDue('0 2 * * *', $from, $to));
    }

    public function testIsNotDueForEmptyOrInvertedWindow(): void
    {
        $this->assertFalse($this->schedule->isDue('* * * * *', 1000, 1000));
        $this->assertFalse($this->schedule->isDue('* * * * *', 2000, 1000));
    }

    public function testWindowIsCappedToOneDay(): void
    {
        // A two-day window ending at 01:00 must not reach back to 02:00 two days earlier.
        $to   = (int) (new \DateTime('2026-03-12 01:00:00', new \DateTimeZone('UTC')))->format('U');
        $from = $to - 3 * 86400;
        $this->assertTrue($this->schedule->isDue('0 2 * * *', $from, $to), 'previous day 02:00 is within 24h');
        $this->assertFalse($this->schedule->isDue('0 2 10 3 *', $from, $to), 'two days back is outside cap');
    }
}
