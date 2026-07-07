<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Store\Model\StoreManagerInterface;
use Panth\StructuredData\Model\LandingPage\LandingPageDetector;
use Panth\XmlSitemap\Api\ContributorInterface;

class LandingPageContributor implements ContributorInterface
{
    private const PRIORITY   = 0.8;
    private const CHANGEFREQ = 'weekly';

    public function __construct(
        private readonly LandingPageDetector $detector,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getCode(): string
    {
        return 'landing_page';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $pages   = $this->detector->getLandingPages($storeId);

        foreach ($pages as $row) {
            $identifier = (string) ($row['identifier'] ?? '');
            if ($identifier === '' || $identifier === 'no-route') {
                continue;
            }

            $entry = [
                'loc'        => $baseUrl . ltrim($identifier, '/'),
                'changefreq' => self::CHANGEFREQ,
                'priority'   => self::PRIORITY,
            ];

            if (!empty($row['update_time'])) {
                try {
                    $entry['lastmod'] = (new \DateTimeImmutable((string) $row['update_time']))
                        ->format('Y-m-d\TH:i:sP');
                } catch (\Throwable) {
                }
            }

            yield $entry;
        }
    }
}
