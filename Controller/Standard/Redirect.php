<?php
namespace Sezzle\Sezzlepay\Controller\Standard;
class Redirect extends \Sezzle\Sezzlepay\Controller\Sezzlepay
{
    public function execute()
    {
        $quote = $this->_checkoutSession->getQuote();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $customerSession = $objectManager->get('Magento\Customer\Model\Session');
        $customerRepository = $objectManager->get('Magento\Customer\Api\CustomerRepositoryInterface');
        if($customerSession->isLoggedIn()) {
            $customerId = $customerSession->getCustomer()->getId();
            $customer = $customerRepository->getById($customerId);
            $quote->setCustomer($customer);
            $billingAddress  = $quote->getBillingAddress();
            $shippingAddress = $quote->getShippingAddress();
            if( empty($shippingAddress) || empty($shippingAddress->getStreetLine(1)) && empty($billingAddress) || empty($billingAddress->getStreetLine(1))  ) {
                die( json_encode( array("success" => false, "message" => "Please select an Address") ) );
            } else if( empty($shippingAddress) || empty($shippingAddress->getStreetLine(1))  || empty($shippingAddress->getFirstname()) ) {
                $shippingAddress = $quote->getBillingAddress();
                $quote->setShippingAddress($object->getBillingAddress());
            } else if( empty($billingAddress) || empty($billingAddress->getStreetLine(1)) || empty($billingAddress->getFirstname()) ) {
                $billingAddress = $quote->getShippingAddress();
                $quote->setBillingAddress($object->getShippingAddress());
            }
        } else {
            $post = $this->getRequest()->getPostValue();
            if( !empty($post['email']) ) {
                $quote->setCustomerEmail($post['email'])
                    ->setCustomerIsGuest(true)
                    ->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
            }
        }
        $payment = $quote->getPayment();
        $payment->setMethod('sezzlepay');
        $quote->reserveOrderId();
        $orderUrl = $this->_getSezzleRedirectUrl($payment, $quote);

        die(
            json_encode(
                array(
                    "redirectURL" => $orderUrl
                )
            )
        );
    }

    private function createUniqueReferenceId($referenceId) {
        return uniqid() . "-" . $referenceId;
    }

    private function _getSezzleRedirectUrl($quote) {
        $reference = $this->createUniqueReferenceId($quote->getReservedOrderId());
        $response = $this->getSezzlepayModel()->getSezzleRedirectUrl($quote, $reference);
        $json = $this->_jsonHelper->jsonDecode($response->getBody(), true);
        $orderUrl = array_key_exists('checkout_url', $result) ? $result['checkout_url'] : false;
        if (!$orderUrl) {
            $this->_logger->info("No Token response from API");
            throw new \Magento\Framework\Exception\LocalizedException(__('There is an issue processing your order.'));
        }
        return $orderUrl;
    }
}