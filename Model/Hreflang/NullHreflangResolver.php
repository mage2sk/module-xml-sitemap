<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Hreflang;

use Panth\XmlSitemap\Api\HreflangResolverInterface;

class NullHreflangResolver implements HreflangResolverInterface
{
    public function getAlternates(string $entityType, int $entityId, int $storeId): array
    {
        return [];
    }
}
