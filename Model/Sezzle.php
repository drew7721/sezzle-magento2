<?php
/*
 * @category    Sezzle
 * @package     Sezzle_Sezzlepay
 * @copyright   Copyright (c) Sezzle (https://www.sezzle.com/)
 */

namespace Sezzle\Sezzlepay\Model;

use Exception;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Sezzle\Sezzlepay\Api\V1Interface;
use Sezzle\Sezzlepay\Api\V2Interface;
use Sezzle\Sezzlepay\Helper\Data;
use Sezzle\Sezzlepay\Helper\Util;

/**
 * Class Sezzle
 * @package Sezzle\Sezzlepay\Model
 */
class Sezzle extends AbstractMethod
{
    const PAYMENT_CODE = 'sezzlepay';
    const ADDITIONAL_INFORMATION_KEY_REFERENCE_ID = 'sezzle_reference_id';
    const ADDITIONAL_INFORMATION_KEY_ORDER_UUID = 'sezzle_order_uuid';
    const SEZZLE_AUTH_EXPIRY = 'sezzle_auth_expiry';
    const SEZZLE_CAPTURE_EXPIRY = 'sezzle_capture_expiry';
    const SEZZLE_ORDER_TYPE = 'sezzle_order_type';
    const API_V2 = 'v2';

    const ADDITIONAL_INFORMATION_KEY_REFERENCE_ID_V1 = 'sezzle_order_id';

    const ADDITIONAL_INFORMATION_KEY_AUTH_AMOUNT = 'sezzle_auth_amount';
    const ADDITIONAL_INFORMATION_KEY_CAPTURE_AMOUNT = 'sezzle_capture_amount';
    const ADDITIONAL_INFORMATION_KEY_REFUND_AMOUNT = 'sezzle_refund_amount';
    const ADDITIONAL_INFORMATION_KEY_RELEASE_AMOUNT = 'sezzle_order_amount';

    const ADDITIONAL_INFORMATION_KEY_GET_ORDER_LINK = 'sezzle_get_order_link';
    const ADDITIONAL_INFORMATION_KEY_CAPTURE_LINK = 'sezzle_capture_link';
    const ADDITIONAL_INFORMATION_KEY_REFUND_LINK = 'sezzle_refund_link';
    const ADDITIONAL_INFORMATION_KEY_RELEASE_LINK = 'sezzle_release_link';
    const ADDITIONAL_INFORMATION_KEY_CREATE_ORDER_LINK = 'sezzle_create_order_link';
    const ADDITIONAL_INFORMATION_KEY_GET_CUSTOMER_LINK = 'sezzle_get_customer_link';
    const ADDITIONAL_INFORMATION_KEY_GET_TOKEN_DETAILS_LINK = 'sezzle_token_link';

    /**
     * @var string
     */
    protected $_code = self::PAYMENT_CODE;
    /**
     * @var bool
     */
    protected $_isGateway = true;
    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;
    /**
     * @var bool
     */
    protected $_canOrder = true;
    /**
     * @var bool
     */
    protected $_canAuthorize = true;
    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = true;
    /**
     * @var bool
     */
    protected $_canRefund = true;
    /**
     * @var bool
     */
    protected $_canVoid = true;
    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;
    /**
     * @var bool
     */
    protected $_canUseInternal = false;
    /**
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;

    /**
     * @var Data
     */
    protected $sezzleHelper;

    /**
     * @var V2Interface
     */
    protected $v2;
    /**
     * @var QuoteRepository
     */
    private $quoteRepository;
    /**
     * @var CustomerSession
     */
    protected $customerSession;
    /**
     * @var System\Config\Container\SezzleConfigInterface
     */
    private $sezzleConfig;
    /**
     * @var Tokenize
     */
    private $tokenizeModel;
    /**
     * @var V1Interface
     */
    private $v1;
    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * Sezzle constructor.
     * @param Context $context
     * @param System\Config\Container\SezzleConfigInterface $sezzleConfig
     * @param Data $sezzleHelper
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $mageLogger
     * @param QuoteRepository $quoteRepository
     * @param V2Interface $v2
     * @param CustomerSession $customerSession
     * @param Tokenize $tokenizeModel
     * @param V1Interface $v1
     * @param DateTime $dateTime
     */
    public function __construct(
        Context $context,
        System\Config\Container\SezzleConfigInterface $sezzleConfig,
        Data $sezzleHelper,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $mageLogger,
        QuoteRepository $quoteRepository,
        V2Interface $v2,
        CustomerSession $customerSession,
        Tokenize $tokenizeModel,
        V1Interface $v1,
        DateTime $dateTime
    ) {
        $this->sezzleHelper = $sezzleHelper;
        $this->sezzleConfig = $sezzleConfig;
        $this->quoteRepository = $quoteRepository;
        $this->v2 = $v2;
        $this->customerSession = $customerSession;
        $this->tokenizeModel = $tokenizeModel;
        $this->v1 = $v1;
        $this->dateTime = $dateTime;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $mageLogger
        );
    }

    /**
     * Get Sezzle checkout url
     *
     * @param Quote $quote
     * @return string
     * @throws LocalizedException
     * @throws Exception
     */
    public function getSezzleRedirectUrl($quote)
    {
        $payment = $quote->getPayment();
        $referenceID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID);
        $this->sezzleHelper->logSezzleActions("Reference Id : $referenceID");
        $this->sezzleHelper->logSezzleActions("Payment Type : " . $this->getConfigPaymentAction());
        $additionalInformation[self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID] = $referenceID;
        $redirectURL = '';
        if ((!$this->sezzleConfig->isInContextModeEnabled()
            || $this->sezzleConfig->isMobileOrTablet())
            && $quote->getCustomer()
            && $this->tokenizeModel->isCustomerUUIDValid($quote)) {
            $this->sezzleHelper->logSezzleActions("Tokenized Checkout");
            $tokenizeInformation = [
                Tokenize::ATTR_SEZZLE_CUSTOMER_UUID => $quote->getCustomer()->getCustomAttribute(Tokenize::ATTR_SEZZLE_CUSTOMER_UUID)->getValue(),
                Tokenize::ATTR_SEZZLE_CUSTOMER_UUID_EXPIRATION => $quote->getCustomer()->getCustomAttribute(Tokenize::ATTR_SEZZLE_CUSTOMER_UUID_EXPIRATION)->getValue(),
                self::ADDITIONAL_INFORMATION_KEY_CREATE_ORDER_LINK => $quote->getCustomer()->getCustomAttribute(self::ADDITIONAL_INFORMATION_KEY_CREATE_ORDER_LINK)->getValue(),
            ];
            $additionalInformation = array_merge($additionalInformation, $tokenizeInformation);
            $redirectURL = $this->sezzleConfig->getTokenizePaymentCompleteURL();
        } else {
            $this->sezzleHelper->logSezzleActions("Typical Checkout");
            $session = $this->v2->createSession($referenceID);
            if ($session->getOrder()) {
                $redirectURL = $session->getOrder()->getCheckoutUrl();
                if ($session->getOrder()->getUuid()) {
                    $orderUUID = [
                        self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID => $session->getOrder()->getUuid()
                    ];
                    $additionalInformation = array_merge($additionalInformation, $orderUUID);
                }
                $links = [];
                if (is_array($session->getOrder()->getLinks())) {
                    foreach ($session->getOrder()->getLinks() as $link) {
                        $rel = "sezzle_" . $link->getRel() . "_link";
                        if ($link->getMethod() == 'GET' && strpos($rel, "self") !== false) {
                            $rel = self::ADDITIONAL_INFORMATION_KEY_GET_ORDER_LINK;
                        }
                        $links[$rel] = $link->getHref();
                    }
                    $additionalInformation = array_merge($additionalInformation, $links);
                }
            }
            if ($tokenizeObject = $session->getTokenize()) {
                $this->customerSession->setCustomerSezzleToken($tokenizeObject->getToken());
                $this->customerSession->setCustomerSezzleTokenExpiration($tokenizeObject->getExpiration());
                $this->customerSession->setCustomerSezzleTokenStatus(true);

                if (is_array($tokenizeObject->getLinks())) {
                    foreach ($tokenizeObject->getLinks() as $link) {
                        if ($link->getRel() == self::ADDITIONAL_INFORMATION_KEY_GET_TOKEN_DETAILS_LINK) {
                            $this->customerSession->setGetTokenDetailsLink($link->getHref());
                        }
                    }
                }
            }
        }
        if (!$redirectURL) {
            $this->sezzleHelper->logSezzleActions("Redirect URL was not received from Sezzle.");
            throw new LocalizedException(__('Unable to start your checkout with Sezzle.'));
        }
        $payment->setAdditionalInformation(array_merge(
            $additionalInformation,
            [self::SEZZLE_ORDER_TYPE => self::API_V2]
        ));
        $this->quoteRepository->save($quote);
        $this->sezzleHelper->logSezzleActions("Checkout URL : $redirectURL");
        return $redirectURL;
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return Sezzle|void
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        switch ($paymentAction) {
            case self::ACTION_AUTHORIZE:
                $payment = $this->getInfoInstance();
                /** @var Order $order */
                $order = $payment->getOrder();
                $order->setCanSendNewEmailFlag(false);
                $payment->authorize(true, $order->getBaseTotalDue()); // base amount will be set inside
                $payment->setAmountAuthorized($order->getTotalDue());
                $orderStatus = $order->getConfig()->getStateDefaultStatus(Order::STATE_NEW);
                $order->setCustomerNote("Payment authorized by Sezzle.");
                $stateObject->setState(Order::STATE_NEW);
                $stateObject->setStatus($orderStatus);
                $stateObject->setIsNotified(true);
                break;
            case self::ACTION_AUTHORIZE_CAPTURE:
                $payment = $this->getInfoInstance();
                /** @var Order $order */
                $order = $payment->getOrder();
                $order->setCanSendNewEmailFlag(false);
                $payment->capture(null);
                $orderStatus = $order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING);
                $order->setCustomerNote("Payment captured by Sezzle.");
                $stateObject->setState(Order::STATE_PROCESSING);
                $stateObject->setStatus($orderStatus);
                $stateObject->setIsNotified(true);
                break;
            default:
                break;
        }
    }

    /**
     * Send authorize request to gateway
     *
     * @param DataObject|InfoInterface $payment
     * @param float $amount
     * @return Sezzle
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @throws LocalizedException
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        if (!$this->canAuthorize()) {
            throw new LocalizedException(__('The authorize action is not available.'));
        } elseif ($amount <= 0) {
            throw new LocalizedException(__('Invalid amount for authorize.'));
        }
        $this->sezzleHelper->logSezzleActions("****Authorization start****");
        $reference = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID);

        $amountInCents = Util::formatToCents($amount);
        $this->sezzleHelper->logSezzleActions("Sezzle Reference ID : $reference");
        $sezzleOrderUUID = "";
        if ($sezzleCustomerUUID = $payment->getAdditionalInformation(Tokenize::ATTR_SEZZLE_CUSTOMER_UUID)) {
            $this->tokenizeModel->createOrder($payment, $amountInCents);
        }
        if (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        }
        $this->sezzleHelper->logSezzleActions("Order validated at Sezzle");
        $this->sezzleHelper->logSezzleActions("Order UUID : $sezzleOrderUUID");
        $authorizedAmount = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_AUTH_AMOUNT);
        $authorizedAmount += $amount;
        $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_AUTH_AMOUNT, $authorizedAmount);
        $payment->setAdditionalInformation('payment_type', $this->getConfigPaymentAction());
        $payment->setTransactionId($reference)->setIsTransactionClosed(false);
        $this->sezzleHelper->logSezzleActions("Transaction ID : $reference");
        $this->sezzleHelper->logSezzleActions("Authorization successful");
        $this->sezzleHelper->logSezzleActions("Authorization end");
        return $this;
    }

    /**
     * Capture at Magento
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return Sezzle
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        $this->sezzleHelper->logSezzleActions("****Capture at Magento start****");
        if (!$this->canCapture()) {
            throw new LocalizedException(__('The capture action is not available.'));
        } elseif ($amount <= 0) {
            throw new LocalizedException(__('Invalid amount for capture.'));
        }
        $reference = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID);
        $amountInCents = Util::formatToCents($amount);
        $payment->setAdditionalInformation('payment_type', $this->getConfigPaymentAction());
        if ($sezzleCustomerUUID = $payment->getAdditionalInformation(Tokenize::ATTR_SEZZLE_CUSTOMER_UUID)) {
            $sezzleOrderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID);
            if (!$sezzleOrderUUID) {
                $this->tokenizeModel->createOrder($payment, $amountInCents);
            }
        }
        if (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        }
        $this->sezzleHelper->logSezzleActions("Order validated at Sezzle");
        $sezzleOrderType = $payment->getAdditionalInformation(self::SEZZLE_ORDER_TYPE);
        $this->sezzleHelper->logSezzleActions("Sezzle Order Type : $sezzleOrderType");
        if ($sezzleOrderType == self::API_V2) {
            $this->handleV2Capture($payment, $amount);
        } else {
            $this->handleV1Capture($payment, $amount);
            $reference = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID_V1);
        }

        $payment->setTransactionId($reference)->setIsTransactionClosed(true);
        $this->sezzleHelper->logSezzleActions("Transaction ID : $reference");
        $this->sezzleHelper->logSezzleActions("****Capture at Magento end****");
        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @return $this|Sezzle
     * @throws LocalizedException
     */
    public function void(InfoInterface $payment)
    {
        $this->sezzleHelper->logSezzleActions("****Release Started****");
        if (!$this->canVoid()) {
            throw new LocalizedException(__('The void action is not available.'));
        } elseif (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        } elseif (!$orderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
            throw new LocalizedException(__('Failed to void the payment.'));
        }
        $this->sezzleHelper->logSezzleActions("Order validated at Sezzle");
        $amountInCents = Util::formatToCents($payment->getOrder()->getBaseGrandTotal());

        $url = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_RELEASE_LINK);
        $this->v2->release($url, $orderUUID, $amountInCents);
        $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_RELEASE_AMOUNT, $payment->getOrder()->getBaseGrandTotal());
        $payment->getOrder()->setState(Order::STATE_CLOSED)
                ->setStatus($payment->getOrder()->getConfig()->getStateDefaultStatus(Order::STATE_CLOSED));
        $this->sezzleHelper->logSezzleActions("Released payment successfully");
        $this->sezzleHelper->logSezzleActions("****Release end****");

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this|Sezzle
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $this->sezzleHelper->logSezzleActions("****Refund Started****");
        if (!$this->canRefund()) {
            throw new LocalizedException(__('The refund action is not available.'));
        } elseif ($amount <= 0) {
            throw new LocalizedException(__('Invalid amount for refund.'));
        } elseif (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        }
        $this->sezzleHelper->logSezzleActions("Order validated at Sezzle");
        $amountInCents = Util::formatToCents($amount);
        $sezzleOrderType = $payment->getAdditionalInformation(self::SEZZLE_ORDER_TYPE);
        if ($sezzleOrderType == self::API_V2) {
            if (!$sezzleOrderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
                throw new LocalizedException(__('Failed to refund the payment. Order UUID is missing.'));
            }
            $url = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFUND_LINK);
            $this->v2->refund($url, $sezzleOrderUUID, $amountInCents);
            $refundedAmount = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFUND_AMOUNT);
            $refundedAmount += $amount;
            $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFUND_AMOUNT, $refundedAmount);
        } else {
            $orderReferenceID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID_V1);
            if (!$orderReferenceID) {
                throw new LocalizedException(__('Failed to refund the payment. Order Reference ID is missing.'));
            }
            $this->v1->refund($orderReferenceID, $amountInCents);
        }
        $this->sezzleHelper->logSezzleActions("Refunded payment successfully");
        $this->sezzleHelper->logSezzleActions("****Refund end****");

        return $this;
    }

    /**
     * Check whether payment method can be used
     *
     * @param CartInterface|null $quote
     * @return bool
     * @throws LocalizedException
     * @deprecated 100.2.0
     */
    public function isAvailable(CartInterface $quote = null)
    {
        if (!$this->isActive($quote ? $quote->getStoreId() : null)) {
            return false;
        }

        $checkResult = new DataObject();
        $checkResult->setData('is_available', true);

        $merchantUUID = $this->sezzleConfig->getMerchantUUID();
        $publicKey = $this->sezzleConfig->getPublicKey();
        $privateKey = $this->sezzleConfig->getPrivateKey();
        $minCheckoutAmount = $this->sezzleConfig->getMinCheckoutAmount();

        if (($this->getCode() == self::PAYMENT_CODE)
            && ((!$merchantUUID || !$publicKey || !$privateKey)
                || ($quote && ($quote->getBaseGrandTotal() < $minCheckoutAmount)))) {
            $checkResult->setData('is_available', false);
        }

        return $checkResult->getData('is_available');
    }

    /**
     * Validate Order
     *
     * @param InfoInterface $payment
     * @return bool
     * @throws LocalizedException
     */
    private function validateOrder($payment)
    {
        if ($sezzleOrderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
            $url = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_GET_ORDER_LINK);
            $sezzleOrder = $this->v2->getOrder($url, $sezzleOrderUUID);
            if ($sezzleOrderUUID != $sezzleOrder->getUuid()) {
                $this->sezzleHelper->logSezzleActions("Order UUID not matching.");
                return false;
            } elseif (!$sezzleOrder->getAuthorization()) {
                $this->sezzleHelper->logSezzleActions("Order not authorized. Issue might be with limit.");
                return false;
            }
        } else {
            $orderReferenceID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID_V1);
            if (!$orderReferenceID) {
                return false;
            }
            $sezzleOrder = $this->v1->getOrder($orderReferenceID);
            if (!$sezzleOrder->getCaptureExpiration()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set Sezzle Auth Expiry
     *
     * @param OrderInterface $order
     * @return void
     * @throws LocalizedException
     */
    public function setSezzleAuthExpiry($order)
    {
        $sezzleOrderUUID = $order->getPayment()->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID);
        $url = $order->getPayment()->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_GET_ORDER_LINK);
        $sezzleOrder = $this->v2->getOrder((string)$url, (string)$sezzleOrderUUID);
        if ($auth = $sezzleOrder->getAuthorization()) {
            $order->getPayment()->setAdditionalInformation(self::SEZZLE_AUTH_EXPIRY, $auth->getExpiration())->save();
        }
    }

    /**
     * @param OrderInterface $order
     * @return bool
     * @throws NoSuchEntityException
     */
    public function canInvoice($order)
    {
        if ($order->getPayment()->getMethod() == Sezzle::PAYMENT_CODE) {
            $sezzleOrderType = $order->getPayment()->getAdditionalInformation(self::SEZZLE_ORDER_TYPE);
            $currentTimestamp = $this->dateTime->timestamp('now');
            if ($sezzleOrderType == Sezzle::API_V2) {
                $authExpiry = $order->getPayment()->getAdditionalInformation(self::SEZZLE_AUTH_EXPIRY);
                $expirationTimestamp = $this->dateTime->timestamp($authExpiry);
                $this->sezzleHelper->logSezzleActions("Authorization valid.");
            } else {
                $captureExpiry = $order->getPayment()->getAdditionalInformation(self::SEZZLE_CAPTURE_EXPIRY);
                $expirationTimestamp = $this->dateTime->timestamp($captureExpiry);
                $this->sezzleHelper->logSezzleActions("Authorization valid.");
            }
            if ($expirationTimestamp < $currentTimestamp) {
                $this->sezzleHelper->logSezzleActions("Authorization expired. Invoice operation is not permitted any more.");
                return false;
            }
        }
        return true;
    }

    /**
     * Handling of V1 Capture
     *
     * @param InfoInterface $payment
     * @param int $amount
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function handleV1Capture($payment, $amount)
    {
        $orderReferenceID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID_V1);
        $amountInCents = Util::formatToCents($amount);
        if (!$orderReferenceID) {
            throw new LocalizedException(__("Unable to capture. Order Reference ID is missing."));
        }
        $sezzleOrder = $this->v1->getOrder($orderReferenceID);
        if ($amountInCents != $sezzleOrder->getAmountInCents()) {
            throw new LocalizedException(__('Unable to capture due to invalid order total.'));
        } elseif ($sezzleOrder->getCaptureExpiration() == null) {
            throw new LocalizedException(__('Unable to capture as the order is not authorized.'));
        }
        $this->v1->capture($orderReferenceID);
    }

    /**
     * Handling of V2 Capture
     *
     * @param InfoInterface $payment
     * @param int $amount
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function handleV2Capture($payment, $amount)
    {
        $sezzleOrderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID);
        $amountInCents = Util::formatToCents($amount);
        $orderTotalInCents = Util::formatToCents($payment->getOrder()->getBaseGrandTotal());
        $this->sezzleHelper->logSezzleActions("Order UUID : $sezzleOrderUUID");
        $url = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_CAPTURE_LINK);
        $this->v2->capture($url, $sezzleOrderUUID, $amountInCents, $amountInCents < $orderTotalInCents);
        if (!$payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
            $payment->setAdditionalInformation(
                self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID,
                $sezzleOrderUUID
            );
        }
        $capturedAmount = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_CAPTURE_AMOUNT);
        $capturedAmount += $amount;
        if (!$authAmount = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_AUTH_AMOUNT)) {
            $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_AUTH_AMOUNT, $capturedAmount);
        }
        $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_CAPTURE_AMOUNT, $capturedAmount);
    }
}
