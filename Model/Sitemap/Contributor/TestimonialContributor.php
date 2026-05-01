<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Psr\Log\LoggerInterface;

/**
 * Sitemap contributor for `Panth_Testimonials`.
 *
 * **Optional integration** — this class never references any class
 * from `Panth_Testimonials`. Behaviour is fully conditional on the
 * source module's tables being present:
 *
 *   - `panth_testimonial`           individual testimonials (status, url_key, store_id, updated_at)
 *   - `panth_testimonial_category`  category landing pages
 *
 * If neither table exists (the merchant hasn't installed the
 * testimonials module on this site) the contributor yields zero
 * URLs and emits no log noise. If one of the two tables is missing,
 * only the present one contributes.
 *
 * URL patterns produced (matches Panth_Testimonials Controller/Router.php):
 *   - /{base}/                          → listing page
 *   - /{base}/{url_key}                 → individual testimonial (status = 1 / approved)
 *   - /{base}/category/{url_key}        → category landing (is_active = 1)
 *
 * The base segment defaults to `testimonials` but can be configured
 * by the merchant in Stores → Configuration → Panth → Testimonials →
 * URL Route. The source module's `system.xml` writes that field to
 * `panth_testimonials/general/route` (matches Helper\Data::XML_PATH_ROUTE),
 * which is the exact path we read here. v1.0.11 mistakenly read
 * `panth_testimonials/general/base_url` — a path that nothing writes —
 * so a merchant who renamed the route to e.g. `reviews` ended up with
 * sitemap URLs pointing at `/testimonials/{slug}` (404).
 */
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
            $this->logger->info('[Panth_XmlSitemap] testimonial contributor — db unavailable: ' . $e->getMessage());
            return;
        }

        $itemTable     = $this->resource->getTableName(self::ITEM_TABLE);
        $categoryTable = $this->resource->getTableName(self::CATEGORY_TABLE);

        $hasItems      = $conn->isTableExists($itemTable);
        $hasCategories = $conn->isTableExists($categoryTable);
        if (!$hasItems && !$hasCategories) {
            // Source module isn't installed on this site — yield nothing.
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

        // Listing page — always emit when at least one of the source
        // tables exists.
        yield [
            'loc'        => $baseUrl . $base,
            'changefreq' => $changefreq,
            'priority'   => min(1.0, $priority + 0.1),
        ];

        // Category landing pages.
        if ($hasCategories) {
            try {
                $select = $conn->select()
                    ->from($categoryTable, ['url_key', 'updated_at' => new \Zend_Db_Expr('NULL')])
                    ->where('is_active = ?', 1)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
                // updated_at is optional on the category table — fold it in if it exists.
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

        // Individual testimonial pages — `status = 1` is the
        // "approved" state in the source module's enum.
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
