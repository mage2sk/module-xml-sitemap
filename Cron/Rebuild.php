<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Cron;

use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Psr\Log\LoggerInterface;

class Rebuild
{
    public function __construct(
        private readonly SitemapCollectionFactory $collectionFactory,
        private readonly Builder $builder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $profiles = $this->builder->loadActiveProfiles(null, true);

            if (!empty($profiles)) {
                $this->logger->info(sprintf(
                    '[PanthXmlSitemap] Sitemap cron: found %d active cron-enabled profile(s)',
                    count($profiles)
                ));

                foreach ($profiles as $profile) {
                    $profileId = (int) ($profile['profile_id'] ?? 0);
                    $profileName = $profile['name'] ?? 'unnamed';

                    try {
                        $stats = $this->builder->buildFromProfile($profile);

                        $this->builder->updateProfileStats($profileId, $stats);

                        $this->logger->info(sprintf(
                            '[PanthXmlSitemap] Sitemap cron: profile "%s" (id %d) completed — %d URLs, %d files, %.2fs',
                            $profileName,
                            $profileId,
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
