<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Ui\Component\Form;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

class StoreViewSource implements OptionSourceInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int) $store->getId();
            if ($storeId <= 0) {
                continue;
            }
            $options[] = [
                'value' => $storeId,
                'label' => sprintf('%s (%s)', (string) $store->getName(), (string) $store->getCode()),
            ];
        }
        return $options;
    }
}
