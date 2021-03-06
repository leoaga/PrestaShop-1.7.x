<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to tech@dotpay.pl so we can send you a copy immediately.
 *
 * @author    Dotpay Team <tech@dotpay.pl>
 * @copyright Dotpay
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
namespace Dotpay\Processor;

use Dotpay\Action\UpdateCcInfo;
use Dotpay\Action\MakePaymentOrRefund;
use Dotpay\Model\Configuration;
use Dotpay\Model\Payment;
use Dotpay\Model\Notification;
use Dotpay\Model\Seller;
use Dotpay\Resource\Payment as PaymentResource;
use Dotpay\Resource\Seller as SellerResource;
use Dotpay\Exception\Processor\IncorrectRequestException;
use Dotpay\Exception\Processor\SellerNotRecognizedException;
use Dotpay\Exception\Processor\ConfirmationDataException;
use Dotpay\Exception\Processor\ConfirmationInfoException;

/**
 * Processor of confirmation activity
 */
class Confirmation
{
    /**
     * @var string Container of a collected message
     */
    private $outputMessage;
    
    /**
     * @var Configuration Object of Dotpay configuration
     */
    protected $config;
    
    /**
     * @var PaymentResource Object of payment resource
     */
    protected $paymentApi;
    
    /**
     * @var SellerApi Object of seller resource
     */
    protected $sellerApi;
    
    /**
     * @var Payment Object with payment data
     */
    private $payment;
    
    /**
     * @var Notification Object with notification data
     */
    private $notification;
    
    /**
     * @var UpdateCcInfo Action object which is executed during updateing a credit card data
     */
    private $updateCcAction;
    
    /**
     * @var MakePaymentOrRefund Action object which is executed during making a payment
     */
    private $makePaymentAction;
    
    /**
     * @var MakePaymentOrRefund Action object which is executed during making a refund
     */
    private $makeRefundAction;
    
    /**
     * Initialize the processor
     * @param Configuration $config Object of Dotpay configuration
     * @param PaymentResource $paymentApi Object of payment resource
     * @param SellerResource $sellerApi Object of seller resource
     */
    public function __construct(Configuration $config, PaymentResource $paymentApi, SellerResource $sellerApi)
    {
        $this->config = $config;
        $this->paymentApi = $paymentApi;
        $this->sellerApi = $sellerApi;
        $this->outputMessage = '';
        
        $config = $this->config;
        if ($_SERVER['REQUEST_METHOD'] == 'GET' &&
            ($_SERVER['REMOTE_ADDR'] == $config::OFFICE_IP ||
             ($_SERVER['REMOTE_ADDR'] == $config::LOCAL_IP &&
              $config->getTestMode()
             )
            )
            
        ) {
            $this->completeInformations();
            throw new ConfirmationInfoException($this->outputMessage);
        } else if(!($_SERVER['REQUEST_METHOD'] == 'POST' &&
                    ($_SERVER['REMOTE_ADDR'] == $config::CALLBACK_IP ||
                     ($_SERVER['REMOTE_ADDR'] == $config::LOCAL_IP &&
                      $config->getTestMode()
                     )
                    )
                   )
                 ) {
            throw new IncorrectRequestException();
        }
    }
    
    /**
     * Set an action which is executed during updateing a credit card data
     * @param UpdateCcInfo $updateCcAction Action object which is executed during updateing a credit card data
     * @return Confirmation
     */
    public function setUpdateCcAction(UpdateCcInfo $updateCcAction)
    {
        $this->updateCcAction = $updateCcAction;
        return $this;
    }
    
    /**
     * Set an action which is executed during making a payment
     * @param MakePaymentOrRefund $makePaymentAction Action object which is executed during making a payment
     * @return Confirmation
     */
    public function setMakePaymentAction(MakePaymentOrRefund $makePaymentAction)
    {
        $this->makePaymentAction = $makePaymentAction;
        return $this;
    }
    
    /**
     * Set an action which is executed during making a refund
     * @param MakePaymentOrRefund $makeRefundAction Action object which is executed during making a refund
     * @return Confirmation
     */
    public function setMakeRefundAction(MakePaymentOrRefund $makeRefundAction)
    {
        $this->makeRefundAction = $makeRefundAction;
        return $this;
    }
    
    /**
     * Execute the processor for making all confirmation's activities
     * @param Payment $payment Payment data
     * @param Notification $notification Notification data
     * @return boolean
     * @throws ConfirmationInfoException Thrown when info for customer service can be cought and displayed
     */
    public function execute(Payment $payment, Notification $notification)
    {
        $this->payment = $payment;
        $this->notification = $notification;
        
        $this->checkIp();
        $this->checkMethod();
        $this->checkSignature();
        $this->checkCurrency();
        
        $operation = $this->notification->getOperation();
        switch ($operation->getType()) {
            case $operation::TYPE_PAYMENT:
                return $this->makePayment();
            case $operation::TYPE_REFUND:
                return $this->makeRefund();
            default:
                return false;
        }
    }
    
    /**
     * Collect informations about shop which can be displayed for diagnostic
     */
    protected function completeInformations()
    {
        $config = $this->config;
        $this->addOutputMessage('--- Dotpay Diagnostic Information ---')
             ->addOutputMessage('PHP Version: '.  phpversion())
             ->addOutputMessage('Sdk Version: '.$config::SDK_VERSION)
             ->addOutputMessage('Enabled: '.(int)$config->getEnable(), true) 		 
             ->addOutputMessage('--- Dotpay PLN ---')
             ->addOutputMessage('Id: '.$config->getId())
             ->addOutputMessage('Correct Id: '.(int)$this->paymentApi->checkSeller($config->getId()))
             ->addOutputMessage('Correct Pin: '.(int)$this->sellerApi->checkPin())
             ->addOutputMessage('API Version: '.$config->getApi())
             ->addOutputMessage('Test Mode: '.(int)$config->getTestMode())
             ->addOutputMessage('Refunds: '.(int)$config->getRefundsEnable())
             ->addOutputMessage('Widget: '.(int)$config->getWidgetVisible())
             ->addOutputMessage('Widget currencies: '.$config->getWidgetCurrencies())
             ->addOutputMessage('Instructions: '.(int)$config->getInstructionVisible(), true)
             ->addOutputMessage('--- Separate Channels ---')
             ->addOutputMessage('One Click: '.(int)$config->getOcVisible())
             ->addOutputMessage('Credit Card: '.(int)$config->getCcVisible())
             ->addOutputMessage('MasterPass: '.(int)$config->getMpVisible())
             ->addOutputMessage('Blik: '.(int)$config->getBlikVisible(), true)
             ->addOutputMessage('--- Dotpay FCC ---')
             ->addOutputMessage('FCC Mode: '.(int)$config->getFccVisible())
             ->addOutputMessage('FCC Id: '.$config->getFccId())
             ->addOutputMessage('FCC Correct Id: '.(int)$this->paymentApi->checkSeller($config->getFccId()))
             ->addOutputMessage('FCC Correct Pin: '.(int)$this->sellerApi->checkFccPin())
             ->addOutputMessage('FCC Currencies: '.$config->getFccCurrencies(), true)
             ->addOutputMessage('--- Dotpay API ---')
             ->addOutputMessage('Data: '.(($config->isGoodApiData())?'&lt;given&gt;':'&lt;empty&gt;'))
             ->addOutputMessage('Login: '.$config->getUsername());
        $isAccountRight = false;
        try {
            $isAccountRight = $this->sellerApi->isAccountRight();
        } catch (Exception $ex) {
        }
        $this->addOutputMessage('Correct data: '.$isAccountRight, true);
    }
    
    /**
     * Add a new message to te collector
     * @param string $message Message to add
     * @param boolean $endOfSection Flag if the given message is last in a section
     * @return Confirmation
     */
    protected function addOutputMessage($message, $endOfSection = false)
    {
        $this->outputMessage .= $message."<br />";
        if ($endOfSection) {
            $this->outputMessage .= "<br />";
        }
        return $this;
    }
    
    /**
     * Check if the IP address of a notification is correct
     * @return boolean
     * @throws ConfirmationDataException Thrown when IP address of a notification is incorrect
     */
    protected function checkIp()
    {
        $config = $this->config;
        if (
            !($_SERVER['REMOTE_ADDR'] == $config::CALLBACK_IP ||
                ($this->config->getTestMode() &&
                 ($_SERVER['REMOTE_ADDR'] == $config::OFFICE_IP ||
                  $_SERVER['REMOTE_ADDR'] == $config::LOCAL_IP
                 )
                )
            )
        ) {
            throw new ConfirmationDataException("ERROR (REMOTE ADDRESS: ".$_SERVER['REMOTE_ADDR'].")");
        }
        return true;
    }
    
    /**
     * Check if a HTTP method used during confirmation is correct
     * @return boolean
     * @throws ConfirmationDataException Thrown when sed HTTP method is different than POST
     */
    protected function checkMethod()
    {
        if (strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {
            throw new ConfirmationDataException("ERROR (METHOD <> POST)");
        }
        return true;
    }
    
    /**
     * Check if the notification data is correct through calculating a signature
     * @return boolean
     * @throws ConfirmationDataException Thrown if the given signature is different than calculated based on the notification data
     */
    protected function checkSignature()
    {
        if ($this->notification->calculateSignature($this->getSeller()->getPin()) != $this->notification->getSignature()) {
            throw new ConfirmationDataException("ERROR SIGNATURE - CHECK PIN");
        }
        return true;
    }
    
    /**
     * Check if the given currency is compatible with a currency of the order
     * @return boolean
     * @throws ConfirmationDataException Thrown when the given currency is different than original currency
     */
    protected function checkCurrency()
    {
        $receivedCurrency = $this->notification->getOperation()->getOriginalCurrency();
        $orderCurrency = $this->payment->getOrder()->getCurrency();
        if ($receivedCurrency != $orderCurrency) {
            throw new ConfirmationDataException('ERROR NO MATCH OR WRONG CURRENCY - '.$receivedCurrency.' <> '.$orderCurrency);
        }
        return true;
    }
    
    /**
     * Check if the given currency is compatible with a currency of the order
     * @return boolean
     * @throws ConfirmationDataException Thrown when the given amount is different than original amount
     */
    protected function checkPaymentAmount()
    {
        $receivedAmount = $this->notification->getOperation()->getOriginalAmount();
        $orderAmount = $this->payment->getOrder()->getAmount();
        if ($receivedAmount != $orderAmount) {
            throw new ConfirmationDataException('ERROR NO MATCH OR WRONG AMOUNT - '.$receivedAmount.' <> '.$orderAmount);
        }
        return true;
    }
    
    /**
     * Make a payment and execute all additional actions
     * @return boolean
     */
    protected function makePayment()
    {
        $config = $this->config;
        $this->checkPaymentAmount();
        $operation = $this->notification->getOperation();
        if (
           $operation->getStatus() == $operation::STATUS_COMPLETE &&
           $this->notification->getChannelId() == $config::OC_CHANNEL &&
           $this->updateCcAction !== null
          ) {
            $creditCard = null;
            if ($this->notification->getCreditCard() !== null) {
                $creditCard = $this->notification->getCreditCard();
            } else {
                $operationFromApi = $this->sellerApi->getOperationByNumber($operation->getNumber());
                $paymentMethod = $operationFromApi->getPaymentMethod();
                if ($paymentMethod !== null && $paymentMethod->getDetailsType() == $paymentMethod::CREDIT_CARD) {
                    $creditCard = $paymentMethod->getDetails();
                }
            }
            if ($creditCard !== null) {
                $this->updateCcAction->setCreditCard($creditCard);
                $this->updateCcAction->execute();
            }
        }
        if($this->makePaymentAction !== null) {
            $this->makePaymentAction->setOperation($operation);
            return $this->makePaymentAction->execute();
        } else {
            return true;
        }
    }
    
    /**
     * Make a refund and execute all additional actions
     * @return boolean
     */
    protected function makeRefund()
    {
        $this->makeRefundAction->setOperation($this->notification->getOperation());
        return ($this->makeRefundAction !== null)?$this->makeRefundAction->execute():true;
    }
    
    /**
     * Return a Seller object with a data of seller which applies the given notivication
     * @return Seller
     * @throws SellerNotRecognizedException Thrown when a seller is not recognized in configuration
     */
    protected function getSeller()
    {
        switch ($this->notification->getOperation()->getAccountId()) {
            case $this->config->getId():
                return new Seller($this->config->getId(), $this->config->getPin());
            case $this->config->getFccId():
                if (
                    $this->config->getFccVisible() &&
                    $this->config->isCurrencyForFcc(
                        $this->notification->getOperation()->getOriginalCurrency()
                    )
                  ) {
                    return new Seller($this->config->getFccId(), $this->config->getFccPin());
                } else {
                    throw new SellerNotRecognizedException($this->notification->getAccountId());
                }
            default:
                throw new SellerNotRecognizedException($this->notification->getAccountId());
        }
    }
}
