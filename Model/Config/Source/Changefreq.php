<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for sitemap <changefreq> values per the Sitemaps protocol.
 */
class Changefreq implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'always',  'label' => __('Always')],
            ['value' => 'hourly',  'label' => __('Hourly')],
            ['value' => 'daily',   'label' => __('Daily')],
            ['value' => 'weekly',  'label' => __('Weekly')],
            ['value' => 'monthly', 'label' => __('Monthly')],
            ['value' => 'yearly',  'label' => __('Yearly')],
            ['value' => 'never',   'label' => __('Never')],
        ];
    }
}
