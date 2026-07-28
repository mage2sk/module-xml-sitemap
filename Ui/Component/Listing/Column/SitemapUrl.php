<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\Listing\Columns\Column;
use Panth\XmlSitemap\Helper\PathResolver;

class SitemapUrl extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly PathResolver $pathResolver,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = (string) $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $fileCount = (int) ($item['file_count'] ?? 0);
            if ($fileCount === 0) {
                $item[$name] = '<span style="color:#9b9b9b;">-</span>';
                continue;
            }
            $url = $this->buildUrl($item);
            if ($url === '') {
                $item[$name] = '';
                continue;
            }
            $item[$name] = sprintf(
                '<a href="%1$s" target="_blank" rel="noopener" '
                . 'title="Open sitemap in a new tab" style="word-break:break-all;">%1$s</a>',
                htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            );
        }
        unset($item);
        return $dataSource;
    }

    private function buildUrl(array $item): string
    {
        try {
            $storeId = (int) ($item['store_id'] ?? 0);
            if ($storeId <= 0) {
                $storeId = (int) ($this->storeManager->getDefaultStoreView()?->getId() ?: 1);
            }
            $store     = $this->storeManager->getStore($storeId);
            $storeCode = (string) $store->getCode();
            $baseUrl   = (string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB);
            $relDir    = $this->pathResolver->resolveRelativeDir((string) ($item['output_path'] ?? ''), $storeCode);
            return $this->pathResolver->buildSitemapUrl($baseUrl, $relDir);
        } catch (\Throwable) {
            return '';
        }
    }
}
