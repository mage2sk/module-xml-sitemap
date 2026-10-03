<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;

class ProfileSchedule
{
    public const DEFAULT_EXPRESSION = '0 2 * * *';

    private const MAX_WINDOW_SECONDS = 86400;

    private ?Schedule $schedule = null;

    public function __construct(
        private readonly ScheduleFactory $scheduleFactory
    ) {
    }

    public function isValid(string $expression): bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return false;
        }
        try {
            $schedule = $this->getSchedule();
            $schedule->setCronExpr($expression);
            $parts = preg_split('#\s+#', $expression, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!$this->hasValidRanges($parts)) {
                return false;
            }
            foreach (array_slice($parts, 0, 5) as $part) {
                $schedule->matchCronExpression($part, 0);
            }
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    public function normalise(?string $expression): string
    {
        $expression = trim((string) $expression);
        return $this->isValid($expression) ? $expression : self::DEFAULT_EXPRESSION;
    }

    public function isDue(string $expression, int $fromTimestamp, int $toTimestamp): bool
    {
        $expression = $this->normalise($expression);
        if ($toTimestamp <= $fromTimestamp) {
            return false;
        }
        $fromTimestamp = max($fromTimestamp, $toTimestamp - self::MAX_WINDOW_SECONDS);

        $schedule = $this->getSchedule();
        $schedule->setCronExpr($expression);

        $minute = intdiv($fromTimestamp, 60) * 60 + 60;
        $last   = intdiv($toTimestamp, 60) * 60;
        for (; $minute <= $last; $minute += 60) {
            $schedule->setScheduledAt($minute);
            try {
                if ($schedule->trySchedule()) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }

    private function hasValidRanges(array $parts): bool
    {
        $limits = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        foreach (array_slice($parts, 0, 5) as $index => $part) {
            [$min, $max] = $limits[$index];
            foreach (explode(',', strtolower((string) $part)) as $item) {
                $pieces = explode('/', $item, 2);
                if (isset($pieces[1]) && !ctype_digit($pieces[1])) {
                    return false;
                }
                foreach (explode('-', $pieces[0]) as $value) {
                    if (!ctype_digit($value)) {
                        continue;
                    }
                    if ((int) $value < $min || (int) $value > $max) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function getSchedule(): Schedule
    {
        if ($this->schedule === null) {
            $this->schedule = $this->scheduleFactory->create();
        }
        return $this->schedule;
    }
}
