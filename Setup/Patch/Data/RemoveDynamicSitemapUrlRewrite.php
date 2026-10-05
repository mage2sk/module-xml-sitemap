<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class RemoveDynamicSitemapUrlRewrite implements DataPatchInterface
{
    public const REQUEST_PATH = 'panth-sitemap.xml';

    public const TARGET_PATH = 'xml_sitemap/sitemap/index';

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

        $connection->delete($table, [
            'request_path = ?' => self::REQUEST_PATH,
            'target_path = ?'  => self::TARGET_PATH,
        ]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
