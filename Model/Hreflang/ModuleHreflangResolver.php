<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Hreflang;

use Magento\Framework\ObjectManagerInterface;
use Panth\XmlSitemap\Api\HreflangResolverInterface;
use Panth\XmlSitemap\Helper\Config;
use Psr\Log\LoggerInterface;

class ModuleHreflangResolver implements HreflangResolverInterface
{
    private const SOURCE_INTERFACE = 'Panth\\Hreflang\\Api\\HreflangResolverInterface';

    private ?object $source = null;

    private bool $sourceResolved = false;

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getAlternates(string $entityType, int $entityId, int $storeId): array
    {
        if (!$this->config->sitemapUseHreflangModule($storeId)) {
            return [];
        }
        $source = $this->getSource();
        if ($source === null) {
            return [];
        }
        try {
            $rows = $source->getAlternates($entityType, $entityId, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthXmlSitemap] hreflang lookup failed: ' . $e->getMessage());
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        $alternates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $locale = trim((string) ($row['locale'] ?? ''));
            $url    = trim((string) ($row['url'] ?? ''));
            if ($locale === '' || $url === '') {
                continue;
            }
            $alternates[] = ['locale' => $locale, 'url' => $url];
        }
        return $alternates;
    }

    private function getSource(): ?object
    {
        if (!$this->sourceResolved) {
            $this->sourceResolved = true;
            if (interface_exists(self::SOURCE_INTERFACE)) {
                try {
                    $this->source = $this->objectManager->get(self::SOURCE_INTERFACE);
                } catch (\Throwable $e) {
                    $this->logger->warning('[PanthXmlSitemap] hreflang resolver unavailable: ' . $e->getMessage());
                    $this->source = null;
                }
            }
        }
        return $this->source;
    }
}
