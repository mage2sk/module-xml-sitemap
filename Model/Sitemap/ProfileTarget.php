<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;

class ProfileTarget
{
    public const PROFILE_TABLE = 'panth_seo_sitemap_profile';

    public const FILENAME_COLUMN = 'sitemap_filename';

    private const CORE_TABLE = 'sitemap';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly PathResolver $pathResolver,
        private readonly Config $config
    ) {
    }

    public function validateFormat(string $outputPath, string $filename): ?Phrase
    {
        if (!$this->pathResolver->isValidFilename($filename)) {
            return __(
                'The sitemap filename "%1" is not allowed. Use a name that ends in .xml, starts with a letter or '
                . 'digit and contains only letters, digits, dot, dash and underscore, without folders, for example '
                . 'sitemap.xml or sitemap_luma.xml.',
                $filename
            );
        }
        if (!$this->pathResolver->isValidOutputPath($outputPath)) {
            return __(
                'The output path "%1" is not allowed. Enter / for the pub/ folder or folder names made of letters, '
                . 'digits, dot, dash and underscore separated by /, with no .. parts. The {store_code} placeholder '
                . 'is allowed.',
                $outputPath
            );
        }
        return null;
    }

    public function hasFilenameColumn(): bool
    {
        try {
            $connection = $this->resource->getConnection();
            return (bool) $connection->tableColumnExists(
                $this->resource->getTableName(self::PROFILE_TABLE),
                self::FILENAME_COLUMN
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function loadProfile(int $profileId): ?array
    {
        if ($profileId <= 0) {
            return null;
        }
        try {
            $connection = $this->resource->getConnection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resource->getTableName(self::PROFILE_TABLE))
                    ->where('profile_id = ?', $profileId)
            );
        } catch (\Throwable) {
            return null;
        }
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function getFallbackFilename(int $storeId): string
    {
        return $this->pathResolver->resolveFilename(null, $this->config->getSitemapIndexFilename($storeId));
    }

    public function resolveRelativeFile(array $profile): string
    {
        $storeId = (int) ($profile['store_id'] ?? 0);
        [$resolvedId, $storeCode] = $this->resolveStore($storeId);
        $dir = $this->pathResolver->resolveRelativeDir((string) ($profile['output_path'] ?? ''), $storeCode);
        $filename = $this->pathResolver->resolveFilename(
            isset($profile[self::FILENAME_COLUMN]) ? (string) $profile[self::FILENAME_COLUMN] : null,
            $this->config->getSitemapIndexFilename($resolvedId)
        );
        return $this->pathResolver->buildRelativeFile($dir, $filename);
    }

    public function findProfileConflict(int $profileId, array $profile): ?array
    {
        $target = strtolower($this->resolveRelativeFile($profile));
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::PROFILE_TABLE);
        $columns = ['profile_id', 'name', 'store_id', 'output_path'];
        if ($this->hasFilenameColumn()) {
            $columns[] = self::FILENAME_COLUMN;
        }
        $select = $connection->select()->from($table, $columns);
        if ($profileId > 0) {
            $select->where('profile_id <> ?', $profileId);
        }
        foreach ($connection->fetchAll($select) as $row) {
            try {
                $other = $this->resolveRelativeFile($row);
            } catch (\Throwable) {
                continue;
            }
            if (strtolower($other) === $target) {
                return [
                    'profile_id' => (int) $row['profile_id'],
                    'name'       => (string) ($row['name'] ?? ''),
                    'file'       => $other,
                ];
            }
        }
        return null;
    }

    public function findCoreConflicts(array $profile): array
    {
        $target = strtolower($this->resolveRelativeFile($profile));
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::CORE_TABLE);
            if (!$connection->isTableExists($table)) {
                return [];
            }
            $rows = $connection->fetchAll(
                $connection->select()->from($table, ['sitemap_id', 'sitemap_path', 'sitemap_filename'])
            );
        } catch (\Throwable) {
            return [];
        }

        $conflicts = [];
        foreach ($rows as $row) {
            $filename = trim((string) ($row['sitemap_filename'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $segments = array_filter(
                array_map('trim', explode('/', str_replace('\\', '/', (string) ($row['sitemap_path'] ?? '')))),
                static fn(string $segment): bool => $segment !== '' && $segment !== '.'
            );
            $file = $this->pathResolver->buildRelativeFile(implode('/', $segments), $filename);
            if (strtolower($file) === $target) {
                $conflicts[] = ['sitemap_id' => (int) $row['sitemap_id'], 'file' => $file];
            }
        }
        return $conflicts;
    }

    private function resolveStore(int $storeId): array
    {
        if ($storeId <= 0) {
            $default = $this->storeManager->getDefaultStoreView();
            if ($default !== null && (int) $default->getId() > 0) {
                return [(int) $default->getId(), (string) $default->getCode()];
            }
        }
        $store = $this->storeManager->getStore($storeId);
        return [(int) $store->getId(), (string) $store->getCode()];
    }
}
