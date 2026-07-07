<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class SearchEnginePinger
{
    private const ENGINES = [
        'google' => [
            'url'    => 'https://www.google.com/ping?sitemap=',
            'config' => 'panth_xml_sitemap/ping/ping_google',
        ],
        'bing' => [
            'url'    => 'https://www.bing.com/ping?sitemap=',
            'config' => 'panth_xml_sitemap/ping/ping_bing',
        ],
    ];

    private const TIMEOUT = 15;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function ping(string $sitemapUrl): array
    {
        $results = [];

        foreach (self::ENGINES as $engine => $meta) {
            if (!$this->scopeConfig->isSetFlag($meta['config'])) {
                $this->logger->debug(
                    sprintf('[PanthSEO] sitemap ping to %s skipped (disabled in config)', $engine)
                );
                continue;
            }

            $pingUrl = $meta['url'] . urlencode($sitemapUrl);

            try {
                $curl = $this->curlFactory->create();
                $curl->setTimeout(self::TIMEOUT);
                $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
                $curl->setOption(CURLOPT_MAXREDIRS, 3);
                $curl->get($pingUrl);

                $status  = $curl->getStatus();
                $success = $status >= 200 && $status < 300;

                $results[$engine] = [
                    'success' => $success,
                    'status'  => $status,
                ];

                $this->logger->info(
                    sprintf(
                        '[PanthSEO] sitemap ping to %s: HTTP %d (%s) — %s',
                        $engine,
                        $status,
                        $success ? 'OK' : 'FAIL',
                        $pingUrl
                    )
                );
            } catch (\Throwable $e) {
                $results[$engine] = [
                    'success' => false,
                    'status'  => 0,
                ];

                $this->logger->error(
                    sprintf(
                        '[PanthSEO] sitemap ping to %s failed: %s — %s',
                        $engine,
                        $e->getMessage(),
                        $pingUrl
                    )
                );
            }
        }

        return $results;
    }
}
