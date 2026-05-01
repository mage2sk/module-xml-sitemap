<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\ContributorInterface;
use Psr\Log\LoggerInterface;

/**
 * Sitemap contributor for `Panth_DynamicForms`.
 *
 * **Optional integration** — never references a `Panth_DynamicForms`
 * class. The contributor checks for the `panth_dynamic_form` table at
 * runtime and yields zero URLs when the source module isn't installed.
 *
 * URL pattern (matches the source module's Controller/Router.php):
 *   - /pages/{url_key}    for forms with form_type ∈ ('page', 'both')
 *
 * Forms with `form_type = 'widget'` are intentionally skipped — they
 * render inside other CMS pages and don't have a standalone URL.
 *
 * Store scope is honoured via the `store_id` column on the row
 * (`store_id = 0` = "all stores", treated as visible from every
 * store view).
 */
class DynamicFormContributor implements ContributorInterface
{
    private const FORM_TABLE = 'panth_dynamic_form';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return 'dynamic_form';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        try {
            $conn = $this->resource->getConnection();
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] dynamic-form contributor — db unavailable: ' . $e->getMessage());
            return;
        }

        $table = $this->resource->getTableName(self::FORM_TABLE);
        if (!$conn->isTableExists($table)) {
            // Source module isn't installed — yield nothing silently.
            return;
        }

        try {
            $store   = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] dynamic-form store load failed: ' . $e->getMessage());
            return;
        }

        $changefreq = $config['changefreq'] ?? 'monthly';
        $priority   = isset($config['priority']) ? (float) $config['priority'] : 0.4;

        try {
            $cols = $conn->describeTable($table);
            $columns = ['url_key'];
            if (isset($cols['updated_at'])) {
                $columns[] = 'updated_at';
            }
            $select = $conn->select()
                ->from($table, $columns)
                ->where('url_key IS NOT NULL')
                ->where('url_key != ?', '');
            if (isset($cols['is_active'])) {
                $select->where('is_active = ?', 1);
            }
            if (isset($cols['form_type'])) {
                $select->where('form_type IN (?)', ['page', 'both']);
            }
            if (isset($cols['store_id'])) {
                $select->where('store_id IN (?)', [0, $storeId]);
            }

            $stmt = $conn->query($select);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $urlKey = trim((string) ($row['url_key'] ?? ''));
                if ($urlKey === '') {
                    continue;
                }
                $entry = [
                    'loc'        => $baseUrl . 'pages/' . $urlKey,
                    'changefreq' => $changefreq,
                    'priority'   => $priority,
                ];
                if (!empty($row['updated_at'])) {
                    try {
                        $entry['lastmod'] = (new \DateTimeImmutable((string) $row['updated_at']))
                            ->format('Y-m-d\TH:i:sP');
                    } catch (\Throwable) {
                        // skip lastmod on parse failure rather than abort
                    }
                }
                yield $entry;
            }
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_XmlSitemap] dynamic-form rows failed: ' . $e->getMessage());
        }
    }
}
