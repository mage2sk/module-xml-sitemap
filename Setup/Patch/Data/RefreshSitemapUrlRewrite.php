<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Upgrades existing stores that were previously installed via
 * Panth_AdvancedSEO where the `/panth-sitemap.xml` url_rewrite row
 * still points at the legacy target `seo/sitemap/index`.
 *
 * The original {@see InstallSitemapUrlRewrite} patch only inserts when
 * no row exists, so upgrades would keep the stale target and the
 * sitemap URL would 404 (or fall through to the wrong controller).
 * This patch idempotently UPDATEs any surviving legacy rows to the
 * canonical target so upgrading from Panth_AdvancedSEO is seamless.
 */
class RefreshSitemapUrlRewrite implements DataPatchInterface
{
    private const REQUEST_PATH = 'panth-sitemap.xml';
    private const TARGET_PATH  = 'xml_sitemap/sitemap/index';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function apply(): self
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('url_rewrite');
        if (!$connection->isTableExists($table)) {
            return $this;
        }

        $connection->update(
            $table,
            [
                'target_path' => self::TARGET_PATH,
                'description' => 'Panth_XmlSitemap dynamic panth-sitemap.xml',
            ],
            [
                'request_path = ?' => self::REQUEST_PATH,
                'target_path <> ?' => self::TARGET_PATH,
            ]
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [InstallSitemapUrlRewrite::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
