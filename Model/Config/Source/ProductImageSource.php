<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for sitemap product image role selection.
 *
 * Controls which product image attribute is used in image sitemap entries.
 */
class ProductImageSource implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'base_image',  'label' => __('Base Image')],
            ['value' => 'small_image', 'label' => __('Small Image')],
            ['value' => 'thumbnail',   'label' => __('Thumbnail')],
        ];
    }
}
