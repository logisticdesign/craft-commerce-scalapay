<?php

/**
 * Scalapay plugin for Craft CMS 3.x
 *
 * Scalapay payment gateway for Craft Commerce
 *
 * @link      https://logisticdesign.it
 * @copyright Copyright (c) 2021 Logistic Design srl
 */

namespace logisticdesign\scalapay\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\errors\PaymentException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use Exception;
use GuzzleHttp\Client;
use logisticdesign\scalapay\responses\ScalapayResponse;

class ScalapayGateway extends Gateway
{
    /**
     * Live Api Key
     *
     * @var string
     */
    public $liveApiKey;

    /**
     * Sandbox mode enabled.
     *
     * @var bool
     */
    public $sandboxEnabled;

    /**
     * Test Api Key
     *
     * @var string
     */
    protected $testApiKey = 'qhtfs87hjnc12kkos';

    /**
     * Live API Endpoint.
     */
    CONST API_URL = 'https://api.scalapay.com';

    /**
     * Sandbox API Endpoint.
     */
    CONST SANDBOX_API_URL = 'https://staging.api.scalapay.com';

    /**
     * Minimum amount valid for order.
     */
    const MIN_AMOUNT = 100;

    /**
     * Maximum amount valid for order.
     */
    const MAX_AMOUNT = 15000;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Scalapay');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('commerce-scalapay/settings', [
            'gateway' => $this,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params)
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm;
    }

    /**
     * Sandbox Mode is enabled?
     *
     * @return bool
     */
    public function isSandboxEnabled()
    {
        return !! $this->sandboxEnabled;
    }

    /**
     * API Client.
     *
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        $apiKey = $this->isSandboxEnabled() ? $this->testApiKey : $this->liveApiKey;
        $endpoint = $this->isSandboxEnabled() ? self::SANDBOX_API_URL : self::API_URL;

        return new Client([
            'base_uri' => "{$endpoint}/v2/",
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$apiKey}",
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function availableForUseWithOrder(Order $order): bool
    {
        if ( ! Craft::$app->request->getIsSiteRequest()) {
            return false;
        }

        return $order->total >= self::MIN_AMOUNT and $order->total <= self::MAX_AMOUNT;
    }

    /**
     * @inheritdoc
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->createScalapayOrder($transaction);
    }

    /**
     * @inheritdoc
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->createScalapayOrder($transaction);
    }

    /**
     * @inheritdoc
     */
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        return new ScalapayResponse([
            'success' => true,
            'transactionHash' => $transaction->hash,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function createPaymentSource(BasePaymentForm $sourceData, int $userId): PaymentSource
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function deletePaymentSource($token): bool
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function processWebHook(): WebResponse
    {
        return $this->handleScalapayCallback();
    }

    /**
     * @inheritdoc
     */
    public function supportsAuthorize(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCapture(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompletePurchase(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsPurchase(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsRefund(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsPartialRefund(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }


    // -----------------------------------------------------------------------------------------------------------------
    // -----------------------------------------------------------------------------------------------------------------


    protected function createScalapayOrder(Transaction $transaction): RequestResponseInterface
    {
        $order = $transaction->getOrder();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        // API Auth
        $authorization = new \Scalapay\Scalapay\Model\Merchant\Authorization(
            \Scalapay\Scalapay\Model\Merchant\Authorization::SANDBOX_URI,
            \Scalapay\Scalapay\Model\Merchant\Authorization::APIKEY
        );

        // Customer information
        $consumer = new \Scalapay\Scalapay\Model\Merchant\Consumer();
        $consumer->setEmail($order->email);
        $consumer->setGivenNames($billingAddress->firstName);
        $consumer->setSurname($billingAddress->lastName);

        // Billing
        $billing = new \Scalapay\Scalapay\Model\Merchant\Contact();
        $billing->setName("{$billingAddress->firstName} {$billingAddress->lastName}");
        $billing->setLine1($billingAddress->address1);
        $billing->setPostcode($billingAddress->zipCode);
        $billing->setState($billingAddress->city);
        $billing->setCountryCode('IT');

        // Shipping
        $shipping = new \Scalapay\Scalapay\Model\Merchant\Contact();
        $shipping->setName("{$shippingAddress->firstName} {$shippingAddress->lastName}");
        $shipping->setLine1($shippingAddress->address1);
        $shipping->setPostcode($shippingAddress->zipCode);
        $shipping->setState($shippingAddress->city);
        $shipping->setCountryCode('IT');

        // Items
        $itemList = [];

        foreach ($order->getLineItems() as $lineItem) {
            $item = new \Scalapay\Scalapay\Model\Merchant\Item();
            $item->setName($lineItem->getDescription());
            $item->setSku($lineItem->getSku());
            $item->setQuantity($lineItem->qty);

            $itemPrice = new \Scalapay\Scalapay\Model\Merchant\Money();
            $itemPrice->setAmount($lineItem->getTotal());

            $item->setPrice($itemPrice);

            $itemList[] = $item;
        }

        // Confirm & failure URLS
        $merchantOptions = new \Scalapay\Scalapay\Model\Merchant\MerchantOptions();
        $merchantOptions->setRedirectCancelUrl(UrlHelper::url($order->cancelUrl));
        $merchantOptions->setRedirectConfirmUrl(UrlHelper::url($order->returnUrl));

        // Order total
        $totalAmount = new \Scalapay\Scalapay\Model\Merchant\Money();
        $totalAmount->setAmount($order->getTotal());

        // Tax total
        $taxAmount = new \Scalapay\Scalapay\Model\Merchant\Money();
        $taxAmount->setAmount($order->getTotalTax());

        // Shipping total
        $shippingAmount = new \Scalapay\Scalapay\Model\Merchant\Money();
        $shippingAmount->setAmount($order->getTotalShippingCost());

        // Order detail
        $orderDetails = new \Scalapay\Scalapay\Model\Merchant\OrderDetails();
        $orderDetails->setConsumer($consumer);
        $orderDetails->setBilling($billing);
        $orderDetails->setShipping($shipping);
        $orderDetails->setMerchant($merchantOptions);
        $orderDetails->setItems($itemList);
        $orderDetails->setTotalAmount($totalAmount);
        $orderDetails->setShippingAmount($shippingAmount);
        $orderDetails->setTaxAmount($taxAmount);
        $orderDetails->setMerchantReference($transaction->hash);

        // Create Scalapay order
        $scalapayApi = new \Scalapay\Scalapay\Factory\Api();
        $apiResponse = $scalapayApi->createOrder($authorization, $orderDetails);

        return new ScalapayResponse([
            'token' => $apiResponse->getToken(),
            'expires' => $apiResponse->getExpires(),
            'redirectUrl' => $apiResponse->getCheckoutUrl(),
            'transactionHash' => $transaction->hash,
        ]);

        // $data = [
        //     'email' => $order->email,
        //     'firstname' => $billingAddress->firstName,
        //     'lastname' => $billingAddress->lastName,
        //     'amount' => $order->total * 100,
        //     'city' => $billingAddress->city,
        //     'address' => $billingAddress->address1,
        //     'postalCode' => $billingAddress->zipCode,
        //     'successUrl' => UrlHelper::url($order->returnUrl),
        //     'errorUrl' => UrlHelper::url($order->cancelUrl),
        //     'callbackUrl' => $this->getWebhookUrl(),
        //     'orderReference' => $transaction->hash,
        // ];

        // try {
        //     $apiResponse = $this->getClient()->post('orders', [
        //         'json' => $data,
        //     ]);
        // } catch (Exception $e) {
        //     throw new PaymentException($e->getMessage());
        // }

        // $apiResponse = json_decode($apiResponse->getBody(), true) + [
        //     'code' => $apiResponse->getStatusCode(),
        //     'transactionHash' => $transaction->hash,
        // ];

        // return new ScalapayResponse($apiResponse);
    }

    /**
     * Handle callback http request.
     *
     * @todo Handle partial payments on LoanWasDisbursed.
     * @return WebResponse
     */
    protected function handleScalapayCallback(): WebResponse
    {
        $request = Craft::$app->request;
        $response = Craft::$app->getResponse();

        $commerce = Commerce::getInstance();
        $transactionHash = $request->getBodyParam('orderReference');
        $transactionService = $commerce->getTransactions();

        $transaction = $transactionService->getTransactionByHash($transactionHash);

        // Check to see if the transaction exists.
        if ( ! $transaction) {
            Craft::warning('Transaction with the hash "'.$transactionHash.'" not found.', 'scalapay');

            return $response;
        }

        // Check to see if a successful purchase child transaction already exists.
        $successfulChildTransaction = TransactionRecord::find()->where([
            'status' => TransactionRecord::STATUS_SUCCESS,
            'parentId' => $transaction->id,
        ])->one();

        if ($successfulChildTransaction) {
            Craft::warning('Successful child transaction for "'.$transactionHash.'" already exists.', 'scalapay');

            return $response;
        }

        // Ensure that the order was marked as completed.
        if ( ! $transaction->order->isCompleted) {
            $transaction->order->markAsComplete();
        }

        $eventId = $request->getBodyParam('eventId');
        $childTransaction = $transactionService->createTransaction(null, $transaction, $transaction->type);

        switch ($eventId) {
            case 'LoanWasApproved':
            case 'RequestCompleted':
                $childTransaction->status = TransactionRecord::STATUS_PENDING;
                break;

            case 'LoanWasVerified':
                $childTransaction->status = TransactionRecord::STATUS_PROCESSING;
                break;

            case 'LoanWasDisbursed':
                $childTransaction->status = TransactionRecord::STATUS_SUCCESS;

                // Waiting a new Craft Commerce 3.x release that handle partial payments.
                // PR: https://github.com/craftcms/commerce/pull/1903

                // $paymentAmount = (int) $request->getBodyParam('amount');
                // $childTransaction->amount = $paymentAmount / 100;
                break;

            case 'UserWasRejected':
                $childTransaction->status = TransactionRecord::STATUS_FAILED;
                break;
        }

        $childTransaction->code = $eventId;
        $childTransaction->message = $this->translateEventMessage($request->getBodyParam('eventMessage'));
        $childTransaction->response = $request->getBodyParams();
        $childTransaction->reference = $request->getBodyParam('orderToken');

        $transactionService->saveTransaction($childTransaction);

        return $response;
    }

    protected function translateEventMessage($message): string
    {
        return Craft::t('commerce-scalapay', str_replace('%20', ' ', $message));
    }

    protected function throwUnsupportedFunctionalityException()
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }
}
