<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Cron;

use Magento\Framework\FlagManager;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Panth\XmlSitemap\Model\Sitemap\ProfileSchedule;
use Psr\Log\LoggerInterface;

class Rebuild
{
    public const LAST_RUN_FLAG = 'panth_xml_sitemap_cron_last_run';

    private const DEFAULT_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly SitemapCollectionFactory $collectionFactory,
        private readonly Builder $builder,
        private readonly LoggerInterface $logger,
        private readonly ProfileSchedule $profileSchedule,
        private readonly FlagManager $flagManager
    ) {
    }

    public function execute(): void
    {
        $now     = time();
        $lastRun = (int) $this->flagManager->getFlagData(self::LAST_RUN_FLAG);
        if ($lastRun <= 0 || $lastRun > $now) {
            $lastRun = $now - self::DEFAULT_WINDOW_SECONDS;
        }

        try {
            $this->flagManager->saveFlag(self::LAST_RUN_FLAG, $now);
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthXmlSitemap] Sitemap cron: could not store last run time: ' . $e->getMessage());
        }

        try {
            $profiles = $this->builder->loadActiveProfiles(null, true);

            if (!empty($profiles)) {
                foreach ($profiles as $profile) {
                    $profileId   = (int) ($profile['profile_id'] ?? 0);
                    $profileName = $profile['name'] ?? 'unnamed';
                    $expression  = (string) ($profile['cron_schedule'] ?? '');

                    if (!$this->profileSchedule->isDue($expression, $lastRun, $now)) {
                        continue;
                    }

                    try {
                        $stats = $this->builder->buildFromProfile($profile);

                        $this->builder->updateProfileStats($profileId, $stats);

                        $this->logger->info(sprintf(
                            '[PanthXmlSitemap] Sitemap cron: profile "%s" (id %d, schedule "%s") completed - %d URLs, %d files, %.2fs',
                            $profileName,
                            $profileId,
                            $this->profileSchedule->normalise($expression),
                            $stats['url_count'],
                            $stats['file_count'],
                            $stats['generation_time']
                        ));
                    } catch (\Throwable $e) {
                        $this->logger->warning(sprintf(
                            '[PanthXmlSitemap] Sitemap cron: profile "%s" (id %d) failed: %s',
                            $profileName,
                            $profileId,
                            $e->getMessage()
                        ));
                    }
                }

                return;
            }

            if (!$this->profileSchedule->isDue(ProfileSchedule::DEFAULT_EXPRESSION, $lastRun, $now)) {
                return;
            }

            $collection = $this->collectionFactory->create();
            foreach ($collection as $sitemap) {
                try {
                    $sitemap->generateXml();
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Panth XmlSitemap rebuild failed for ' . $sitemap->getId() . ': ' . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Panth XmlSitemap cron error: ' . $e->getMessage());
        }
    }
}
