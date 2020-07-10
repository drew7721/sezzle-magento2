<?php
/*
 * @category    Sezzle
 * @package     Sezzle_Sezzlepay
 * @copyright   Copyright (c) Sezzle (https://www.sezzle.com/)
 */

namespace Sezzle\Sezzlepay\Controller\Payment;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Sezzle\Sezzlepay\Controller\AbstractController\Sezzle;
use Sezzle\Sezzlepay\Model\Tokenize;

/**
 * Class Redirect
 * @package Sezzle\Sezzlepay\Controller\Payment
 */
class Redirect extends Sezzle
{
    /**
     * Redirection
     *
     * @return Json
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        $this->sezzleHelper->logSezzleActions("****Starting Sezzle Checkout****");
        $quote = $this->checkoutSession->getQuote();
        $this->sezzleHelper->logSezzleActions("Quote Id : " . $quote->getId());
        $this->sezzleHelper->logSezzleActions("Order ID from quote : " . $quote->getReservedOrderId());
        if ($this->customerSession->isLoggedIn()) {
            $customerId = $this->customerSession->getCustomer()->getId();
            $this->sezzleHelper->logSezzleActions("Customer Id : $customerId");
            $customer = $this->customerRepository->getById($customerId);
            $quote->setCustomer($customer);
            $billingAddress = $quote->getBillingAddress();
            $shippingAddress = $quote->getShippingAddress();
            if ((empty($shippingAddress) || empty($shippingAddress->getStreetLine(1))) && (empty($billingAddress) || empty($billingAddress->getStreetLine(1)))) {
                $json = $this->jsonHelper->jsonEncode(["message" => "Please select an address"]);
                $jsonResult = $this->resultJsonFactory->create();
                $jsonResult->setData($json);
                return $jsonResult;
            } elseif (empty($billingAddress) || empty($billingAddress->getStreetLine(1)) || empty($billingAddress->getFirstname())) {
                $quote->setBillingAddress($shippingAddress);
            }
        } else {
            $post = $this->getRequest()->getPostValue();
            $this->sezzleHelper->logSezzleActions("Guest customer");
            if (!empty($post['email'])) {
                $quote->setCustomerEmail($post['email'])
                    ->setCustomerIsGuest(true)
                    ->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
            }
        }
        $payment = $quote->getPayment();
        $payment->setMethod(\Sezzle\Sezzlepay\Model\Sezzle::PAYMENT_CODE);
        $quote->reserveOrderId();
        $quote->setPayment($payment);
        $this->cartRepository->save($quote);
        $this->checkoutSession->replaceQuote($quote);
        $checkoutUrl = $this->sezzleModel->getSezzleRedirectUrl($quote);
        if ($quote->getPayment()->getAdditionalInformation(Tokenize::ATTR_SEZZLE_CUSTOMER_UUID)) {
            $this->_forward('complete', null, null, ['tokenize_checkout' => true]);
        }
        $this->sezzleHelper->logSezzleActions("Checkout Url : $checkoutUrl");
        $json = $this->jsonHelper->jsonEncode(["redirectURL" => $checkoutUrl]);
        $jsonResult = $this->resultJsonFactory->create();
        $jsonResult->setData($json);
        return $jsonResult;
    }
}