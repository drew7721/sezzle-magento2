<?php
/*
 * @category    Sezzle
 * @package     Sezzle_Sezzlepay
 * @copyright   Copyright (c) Sezzle (https://www.sezzle.com/)
 */


namespace Sezzle\Sezzlepay\Model\System\Config\Source\Payment;

use Magento\Framework\Option\ArrayInterface;
use Sezzle\Sezzlepay\Model\Sezzle;

/**
 * Sezzle Payment Action Dropdown source
 */
class PaymentAction implements ArrayInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => Sezzle::ACTION_AUTHORIZE,
                'label' => __('Authorize Only'),
            ],
            [
                'value' => Sezzle::ACTION_AUTHORIZE_CAPTURE,
                'label' => __('Authorize and Capture')
            ]
        ];
    }
}
