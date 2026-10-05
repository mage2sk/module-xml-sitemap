<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Psr\Log\LoggerInterface;

class RemoveDisabledSitemaps implements ObserverInterface
{
    public function __construct(
        private readonly Builder $builder,
        private readonly Config $config,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $profiles = $this->builder->loadActiveProfiles();
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthXmlSitemap] Could not load sitemap profiles: ' . $e->getMessage());
            return;
        }

        $removed = 0;
        foreach ($profiles as $profile) {
            $storeId = (int) ($profile['store_id'] ?? 0);
            if ($this->config->isSitemapEnabled($storeId)) {
                continue;
            }
            try {
                $removed += $this->builder->removeProfileFiles($profile);
                $profileId = (int) ($profile['profile_id'] ?? 0);
                if ($profileId > 0) {
                    $this->builder->updateProfileStats(
                        $profileId,
                        ['url_count' => 0, 'file_count' => 0, 'generation_time' => 0]
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[PanthXmlSitemap] Could not remove the sitemap files of profile %d: %s',
                    (int) ($profile['profile_id'] ?? 0),
                    $e->getMessage()
                ));
            }
        }

        if ($removed > 0) {
            $this->messageManager->addNoticeMessage(__(
                'XML Sitemap is disabled for one or more store views, so %1 sitemap file(s) of their profiles '
                . 'were removed. Set Enabled to Yes and click Generate Now to write them again.',
                $removed
            ));
        }
    }
}
