<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Psr\Log\LoggerInterface;

class TestimonialContributor implements ContributorInterface
{
    private const ITEM_TABLE     = 'panth_testimonial';
    private const CATEGORY_TABLE = 'panth_testimonial_category';
    private const XML_ROUTE      = 'panth_testimonials/general/route';
    private const DEFAULT_BASE   = 'testimonials';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return 'testimonial';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        try {
            $conn = $this->resource->getConnection();
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] testimonial contributor - db unavailable: ' . $e->getMessage());
            return;
        }

        $itemTable     = $this->resource->getTableName(self::ITEM_TABLE);
        $categoryTable = $this->resource->getTableName(self::CATEGORY_TABLE);

        $hasItems      = $conn->isTableExists($itemTable);
        $hasCategories = $conn->isTableExists($categoryTable);
        if (!$hasItems && !$hasCategories) {
            return;
        }

        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $base    = trim((string) ($this->scopeConfig->getValue(self::XML_ROUTE, ScopeInterface::SCOPE_STORE, $storeId)
            ?: self::DEFAULT_BASE), '/');
        if ($base === '') {
            $base = self::DEFAULT_BASE;
        }

        $changefreq = $config['changefreq'] ?? 'monthly';
        $priority   = isset($config['priority']) ? (float) $config['priority'] : 0.5;

        yield [
            'loc'        => $baseUrl . $base,
            'changefreq' => $changefreq,
            'priority'   => min(1.0, $priority + 0.1),
        ];

        if ($hasCategories) {
            try {
                $select = $conn->select()
                    ->from($categoryTable, ['url_key', 'updated_at' => new \Zend_Db_Expr('NULL')])
                    ->where('is_active = ?', 1)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');

                $cols = $conn->describeTable($categoryTable);
                if (isset($cols['updated_at'])) {
                    $select->reset(\Magento\Framework\DB\Select::COLUMNS);
                    $select->columns(['url_key', 'updated_at']);
                }

                $stmt = $conn->query($select);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $urlKey = trim((string) ($row['url_key'] ?? ''));
                    if ($urlKey === '') {
                        continue;
                    }
                    $entry = [
                        'loc'        => $baseUrl . $base . '/category/' . $urlKey,
                        'changefreq' => $changefreq,
                        'priority'   => $priority,
                    ];
                    if (!empty($row['updated_at'])) {
                        $entry['lastmod'] = $this->formatLastmod((string) $row['updated_at']);
                    }
                    yield $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->info('[Panth_XmlSitemap] testimonial categories failed: ' . $e->getMessage());
            }
        }

        if ($hasItems) {
            try {
                $cols = $conn->describeTable($itemTable);
                $select = $conn->select()
                    ->from($itemTable, ['url_key'])
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
                if (isset($cols['status'])) {
                    $select->where('status = ?', 1);
                }
                if (isset($cols['store_id'])) {
                    $select->where('store_id IN (?)', [0, $storeId]);
                }
                if (isset($cols['updated_at'])) {
                    $select->reset(\Magento\Framework\DB\Select::COLUMNS);
                    $select->columns(['url_key', 'updated_at']);
                }

                $stmt = $conn->query($select);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $urlKey = trim((string) ($row['url_key'] ?? ''));
                    if ($urlKey === '') {
                        continue;
                    }
                    $entry = [
                        'loc'        => $baseUrl . $base . '/' . $urlKey,
                        'changefreq' => $changefreq,
                        'priority'   => $priority,
                    ];
                    if (!empty($row['updated_at'])) {
                        $entry['lastmod'] = $this->formatLastmod((string) $row['updated_at']);
                    }
                    yield $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->info('[Panth_XmlSitemap] testimonial items failed: ' . $e->getMessage());
            }
        }
    }

    private function formatLastmod(string $raw): ?string
    {
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }
}
