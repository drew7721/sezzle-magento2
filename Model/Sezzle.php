<?php
/*
 * @category    Sezzle
 * @package     Sezzle_Payment
 * @copyright   Copyright (c) Sezzle (https://www.sezzle.com/)
 */

namespace Sezzle\Payment\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Sezzle\Payment\Api\V2Interface;
use Sezzle\Payment\Helper\Data;
use Sezzle\Payment\Model\Api\PayloadBuilder;

/**
 * Class Sezzle
 * @package Sezzle\Payment\Model
 */
class Sezzle extends \Magento\Payment\Model\Method\AbstractMethod
{
    const PAYMENT_CODE = 'sezzle';
    const ADDITIONAL_INFORMATION_KEY_REFERENCE_ID = 'sezzle_reference_id';
    const ADDITIONAL_INFORMATION_KEY_ORDER_UUID = 'sezzle_order_uuid';
    const ADDITIONAL_INFORMATION_KEY_SEZZLE_TOKEN = 'sezzle_token';
    const SEZZLE_CAPTURE_EXPIRY = 'sezzle_capture_expiry';
    const SEZZLE_AUTH_EXPIRY = 'sezzle_auth_expiry';

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
     * @var Order\Payment\Transaction\BuilderInterface
     */
    private $_transactionBuilder;

    /**
     * @var Data
     */
    protected $sezzleHelper;

    /**
     * @var V2Interface
     */
    private $v2;
    /**
     * @var QuoteRepository
     */
    private $quoteRepository;
    /**
     * @var CustomerSession
     */
    private $customerSession;
    /**
     * @var Config\Container\SezzleApiConfigInterface
     */
    private $sezzleApiConfig;

    /**
     * Sezzle constructor.
     * @param Context $context
     * @param Config\Container\SezzleApiConfigInterface $sezzleApiConfig
     * @param Data $sezzleHelper
     * @param Order\Payment\Transaction\BuilderInterface $transactionBuilder
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $mageLogger
     * @param QuoteRepository $quoteRepository
     * @param V2Interface $v2
     * @param CustomerSession $customerSession
     */
    public function __construct(
        Context $context,
        Config\Container\SezzleApiConfigInterface $sezzleApiConfig,
        Data $sezzleHelper,
        Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $mageLogger,
        QuoteRepository $quoteRepository,
        V2Interface $v2,
        CustomerSession $customerSession
    ) {
        $this->sezzleHelper = $sezzleHelper;
        $this->sezzleApiConfig = $sezzleApiConfig;
        $this->_transactionBuilder = $transactionBuilder;
        $this->quoteRepository = $quoteRepository;
        $this->v2 = $v2;
        $this->customerSession = $customerSession;
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
     */
    public function getSezzleRedirectUrl($quote)
    {
        $referenceID = uniqid() . "-" . $quote->getReservedOrderId();
        $this->sezzleHelper->logSezzleActions("Reference Id : $referenceID");
        $payment = $quote->getPayment();
        $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID, $referenceID);
        $session = $this->v2->createSession($referenceID);
        $redirectURL = '';
        if ($quote->getCustomer()
            && ($sezzleToken = $quote->getCustomer()->getCustomAttribute('sezzle_token'))) {
            $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_SEZZLE_TOKEN, $sezzleToken->getValue());
            $redirectURL = $this->sezzleApiConfig->getTokenizePaymentCompleteURL();
        } else {
            if ($session->getOrder()) {
                $redirectURL = $session->getOrder()->getCheckoutUrl();
                if ($session->getOrder()->getUuid()) {
                    $payment->setAdditionalInformation(
                        self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID,
                        $session->getOrder()->getUuid()
                    );
                }
            }
            if ($session->getTokenize()) {
                $this->customerSession->setCustomerSezzleToken($session->getTokenize()->getToken());
                $this->customerSession->setCustomerSezzleTokenExpiration($session->getTokenize()->getExpiration());
                $this->customerSession->setCustomerSezzleTokenStatus('Approved');
            }
        }
        $this->sezzleHelper->logSezzleActions("Redirect URL : $redirectURL");
        if (!$redirectURL) {
            $this->sezzleHelper->logSezzleActions("No Token response from API");
            throw new LocalizedException(__('There is an issue processing your order.'));
        }
        $this->quoteRepository->save($quote);
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
                $order = $payment->getOrder();
                $order->setCanSendNewEmailFlag(false);
                $payment->authorize(true, $order->getBaseTotalDue()); // base amount will be set inside
                $payment->setAmountAuthorized($order->getTotalDue());
                $orderStatus = $payment->getMethodInstance()->getConfigData('order_status');
                $order->setState(Order::STATE_NEW, 'new', '', false);
                $stateObject->setState(Order::STATE_NEW);
                $stateObject->setStatus($orderStatus);
                break;
            case self::ACTION_AUTHORIZE_CAPTURE:
                $payment = $this->getInfoInstance();
                $order = $payment->getOrder();
                $order->setCanSendNewEmailFlag(false);
                $payment->capture(null);
                $payment->setAmountPaid($order->getTotalDue());
                $orderStatus = $payment->getMethodInstance()->getConfigData('order_status');
                $order->setState(Order::STATE_PROCESSING, 'processing', '', false);
                $stateObject->setState(Order::STATE_PROCESSING);
                $stateObject->setStatus($orderStatus);
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
        } elseif (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        }
        $this->sezzleHelper->logSezzleActions("****Authorization start****");
        $reference = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_REFERENCE_ID);

        $amountInCents = (int)(round($amount * 100, PayloadBuilder::PRECISION));
        $this->sezzleHelper->logSezzleActions("Sezzle Reference ID : $reference");
        if ($sezzleToken = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_SEZZLE_TOKEN)) {
            $customerUUID = $this->v2->getCustomerUUID($sezzleToken);
            $response = $this->v2->createOrderByCustomerUUID($customerUUID, $amountInCents);
            if ($orderUUID = $response->getUuid()) {
                $payment->setAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID, $orderUUID);
            }
        }
        $payment->setAdditionalInformation('payment_type', $this->getConfigPaymentAction());
        $payment->setTransactionId($reference)->setIsTransactionClosed(false);
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
        $sezzleOrderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID);
        $amountInCents = (int)(round($amount * 100, PayloadBuilder::PRECISION));
        $payment->setAdditionalInformation('payment_type', $this->getConfigPaymentAction());
        $orderTotalInCents = (int)(round(
            $payment->getOrder()->getBaseGrandTotal() * 100,
            PayloadBuilder::PRECISION
        ));
        if ($sezzleToken = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_SEZZLE_TOKEN)) {
            $customerUUID = $this->v2->getCustomerUUID($sezzleToken);
            $response = $this->v2->createOrderByCustomerUUID(
                $customerUUID,
                $amountInCents
            );
            $sezzleOrderUUID = $response->getUuid();
        }
        if (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        }
        $this->v2->captureByOrderUUID($sezzleOrderUUID, $amountInCents, $amountInCents < $orderTotalInCents);
        if (!$payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
            $payment->setAdditionalInformation(
                self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID,
                $sezzleOrderUUID
            );
        }
        $payment->setTransactionId($reference)->setIsTransactionClosed(true);
        $this->sezzleHelper->logSezzleActions("Authorized on Sezzle");
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
        if (!$this->canVoid()) {
            throw new LocalizedException(__('The void action is not available.'));
        } elseif (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        }
        $amountInCents = (int)(round($payment->getOrder()->getBaseGrandTotal() * 100, PayloadBuilder::PRECISION));
        if ($orderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
            $this->v2->releasePaymentByOrderUUID($orderUUID, $amountInCents);
        } else {
            throw new LocalizedException(__('Failed to void the payment.'));
        }
        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this|Sezzle
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        if (!$this->canRefund()) {
            throw new LocalizedException(__('The refund action is not available.'));
        } elseif ($amount <= 0) {
            throw new LocalizedException(__('Invalid amount for refund.'));
        } elseif (!$this->validateOrder($payment)) {
            throw new LocalizedException(__('Unable to validate the order.'));
        }
        $amountInCents = (int)(round($amount * 100, PayloadBuilder::PRECISION));
        if ($sezzleOrderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
            $this->v2->refundByOrderUUID($sezzleOrderUUID, $amountInCents);
        } else {
            throw new LocalizedException(__('Failed to refund the payment.'));
        }
        return $this;
    }

    /**
     * Check whether payment method can be used
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     * @deprecated 100.2.0
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$this->isActive($quote ? $quote->getStoreId() : null)) {
            return false;
        }

        $checkResult = new DataObject();
        $checkResult->setData('is_available', true);

        $merchantUUID = $this->sezzleApiConfig->getMerchantId();
        $publicKey = $this->sezzleApiConfig->getPublicKey();
        $privateKey = $this->sezzleApiConfig->getPrivateKey();
        $minCheckoutAmount = $this->sezzleApiConfig->getMinCheckoutAmount();

        if (($this->getCode() == self::PAYMENT_CODE)
            && ((!$merchantUUID || !$publicKey || !$privateKey)
                || ($quote && ($quote->getBaseGrandTotal() < $minCheckoutAmount)))) {
            $checkResult->setData('is_available', false);
        }

        return $checkResult->getData('is_available');
    }

    /**
     * Validate Magento stored Order UUID and Sezzle Order UUID
     *
     * @param InfoInterface $payment
     * @return bool
     * @throws LocalizedException
     */
    private function validateOrder($payment)
    {
        if ($sezzleOrderUUID = $payment->getAdditionalInformation(self::ADDITIONAL_INFORMATION_KEY_ORDER_UUID)) {
            $sezzleOrder = $this->v2->getOrder($sezzleOrderUUID);
            if ($sezzleOrderUUID != $sezzleOrder->getUuid()) {
                return false;
            }
            return true;
        }
        return false;
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
        $sezzleOrder = $this->v2->getOrder((string)$sezzleOrderUUID);
        if ($authExpiration = $sezzleOrder->getAuthorization()->getExpiration()) {
            $order->getPayment()->setAdditionalInformation(self::SEZZLE_AUTH_EXPIRY, $authExpiration)->save();
        }
    }

    /**
     * Create transaction
     * @param $order
     * @param $reference
     * @return mixed
     */
    public function createTransaction($order, $reference)
    {
        $this->sezzleHelper->logSezzleActions("****Transaction start****");
        $this->sezzleHelper->logSezzleActions("Order Id : " . $order->getId());
        $this->sezzleHelper->logSezzleActions("Reference Id : $reference");
        $payment = $order->getPayment();
        $payment->setLastTransId($reference);
        $payment->setTransactionId($reference);
        $formattedPrice = $order->getBaseCurrency()->formatTxt(
            $order->getGrandTotal()
        );
        $message = __('The authorized amount is %1.', $formattedPrice);
        $this->sezzleHelper->logSezzleActions($message);
        $txnType = Transaction::TYPE_AUTH;
        $paymentAction = $this->getConfigPaymentAction();
        if ($paymentAction == self::ACTION_AUTHORIZE_CAPTURE) {
            $txnType = Transaction::TYPE_CAPTURE;
        }
        $transaction = $this->_transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($reference)
            ->setFailSafe(true)
            ->build($txnType);

        $payment->addTransactionCommentsToOrder(
            $transaction,
            $message
        );
        $payment->setParentTransactionId(null);
        $payment->save();
        $order->save();
        $transactionId = $transaction->save()->getTransactionId();
        $this->sezzleHelper->logSezzleActions("Transaction Id : $transactionId");
        $this->sezzleHelper->logSezzleActions("****Transaction End****");
        return $transactionId;
    }
}
