<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Hreflang;

use Panth\XmlSitemap\Api\HreflangResolverInterface;

/**
 * Default binding for {@see HreflangResolverInterface}. Returns an empty
 * alternate set, which makes HreflangContributor a silent no-op when the
 * project hasn't wired in a real resolver (e.g. mage2kishan/module-hreflang
 * isn't installed).
 */
class NullHreflangResolver implements HreflangResolverInterface
{
    /**
     * @return array<int, array{locale: string, url: string}>
     */
    public function getAlternates(string $entityType, int $entityId, int $storeId): array
    {
        return [];
    }
}
