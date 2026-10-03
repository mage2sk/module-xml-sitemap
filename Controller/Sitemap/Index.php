<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Controller\Sitemap;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Api\BuilderInterface;
use Panth\XmlSitemap\Model\Sitemap\Builder;

class Index implements HttpGetActionInterface
{
    private const CACHE_KEY_PREFIX = 'panth_xml_sitemap_dynamic_';
    private const CACHE_TAG = 'panth_seo_sitemap';
    private const CACHE_TTL = 3600;
    private const EMPTY_URLSET = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>' . "\n";

    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly BuilderInterface $sitemapBuilder,
        private readonly CacheInterface $cache,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $page    = max(0, (int) $this->request->getParam(Builder::PUBLIC_PAGE_PARAM, 0));
        $status  = 200;

        $body = $this->tryCustomBuilder($storeId, $page);
        if ($body === false) {
            $status = 404;
            $body = self::EMPTY_URLSET;
        }
        if ($body === null && $page === 0) {
            $body = $this->tryMagentoSitemapFile();
        }
        if ($body === null) {
            $body = self::EMPTY_URLSET;
        }

        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Content-Type', 'application/xml; charset=utf-8', true);
        $result->setContents($body);
        return $result;
    }

    private function tryCustomBuilder(int $storeId, int $page): string|false|null
    {
        $prefix = self::CACHE_KEY_PREFIX . $storeId . '_';
        try {
            $cached = $this->cache->load($prefix . $page);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
            if ($page > 0) {
                $first = $this->cache->load($prefix . '0');
                if (is_string($first) && $first !== '') {
                    return false;
                }
            }
            if (!$this->sitemapBuilder instanceof Builder) {
                if ($page > 1) {
                    return false;
                }
                $out = $this->sitemapBuilder->buildForStore($storeId);
                if ($out === '') {
                    return null;
                }
                $this->cache->save($out, $prefix . $page, [self::CACHE_TAG], self::CACHE_TTL);
                return $out;
            }

            $parts = $this->sitemapBuilder->buildPublicSitemap($storeId);
            $pages = $parts['pages'] ?? [];
            if ($pages === []) {
                return null;
            }
            $entries = [];
            if (count($pages) > 1) {
                $entries[0] = (string) $parts['index'];
                foreach ($pages as $number => $xml) {
                    $entries[(int) $number] = (string) $xml;
                }
            } else {
                $entries[0] = (string) reset($pages);
                $entries[1] = $entries[0];
            }
            foreach ($entries as $number => $xml) {
                $this->cache->save($xml, $prefix . $number, [self::CACHE_TAG], self::CACHE_TTL);
            }

            return $entries[$page] ?? false;
        } catch (\Throwable) {
        }
        return null;
    }

    private function tryMagentoSitemapFile(): ?string
    {
        try {
            $pub = $this->filesystem->getDirectoryRead(DirectoryList::PUB);
            foreach (['sitemap.xml', 'sitemap/sitemap.xml'] as $candidate) {
                if ($pub->isFile($candidate)) {
                    return $pub->readFile($candidate);
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
