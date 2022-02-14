<?php

namespace logisticdesign\scalapay\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\errors\PaymentException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Request;
use craft\web\Response as WebResponse;
use craft\web\View;
use Exception;
use GuzzleHttp\Client;
use logisticdesign\scalapay\responses\ScalapayRefundResponse;
use logisticdesign\scalapay\responses\ScalapayResponse;

class ScalapayGateway extends Gateway
{
    // -------------------------------------------------------------------------
    // PUBLIC PROPERTIES
    // -------------------------------------------------------------------------

    /**
     * Live API Key
     *
     * @var string
     */
    public $liveApiKey;

    /**
     * Sandbox API Key
     *
     * @var string
     */
    public $sandboxApiKey;

    /**
     * Sandbox mode enabled.
     *
     * @var bool
     */
    public $sandboxEnabled;

    // -------------------------------------------------------------------------
    // CONSTANTS
    // -------------------------------------------------------------------------

    /**
     * Live API Endpoint.
     *
     * @var string
     */
    CONST API_URL = 'https://api.scalapay.com';

    /**
     * Sandbox API Endpoint.
     *
     * @var string
     */
    CONST SANDBOX_API_URL = 'https://integration.api.scalapay.com';

    /**
     * Minimum amount valid for order.
     *
     * @var integer
     */
    const MIN_AMOUNT = 35;

    /**
     * Maximum amount valid for order.
     * Deve essere concordato con Scalapay.
     *
     * @var integer
     */
    const MAX_AMOUNT = 600;

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
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('craft-commerce-scalapay/settings', [
            'gateway' => $this,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params = [])
    {
        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();

        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = Craft::$app->getView()->renderTemplate('craft-commerce-scalapay/paymentForm', [
            'params' => $params,
        ]);

        $view->setTemplateMode($previousMode);

        return $html;
    }

    // -------------------------------------------------------------------------
    // SUPPORTS
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function supportsAuthorize(): bool
    {
        return false;
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
    public function supportsCapture(): bool
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
    public function supportsCompletePurchase(): bool
    {
        return true;
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
        return false;
    }

    // -------------------------------------------------------------------------
    // PUBLIC METHODS
    // -------------------------------------------------------------------------

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
     * API Key
     *
     * @return string
     */
    public function getApiKey()
    {
        return App::parseEnv(
            $this->isSandboxEnabled() ? $this->sandboxApiKey : $this->liveApiKey
        );
    }

    /**
     * API Endpoint
     *
     * @return string
     */
    public function getApiEndpoint()
    {
        $endpoint = $this->isSandboxEnabled() ? self::SANDBOX_API_URL : self::API_URL;

        return "{$endpoint}/v2/";
    }

    /**
     * API Client.
     *
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        return new Client([
            'base_uri' => $this->getApiEndpoint(),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getApiKey(),
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function availableForUseWithOrder(Order $order): bool
    {
        if ( ! Craft::$app->getRequest()->getIsSiteRequest()) {
            return false;
        }

        return $order->total >= self::MIN_AMOUNT and $order->total <= self::MAX_AMOUNT;
    }

    /**
     * @inheritdoc
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
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
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
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
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $request = Craft::$app->getRequest();

        $token = $request->getQueryParam('orderToken');
        $status = strtolower($request->getQueryParam('status'));

        if ($status === 'success' and $transaction->reference === $token) {
            return $this->capturePayment($token, $transaction);
        }

        return new ScalapayResponse([]);
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
        $request = Craft::$app->getRequest();
        $parentTransaction = $transaction->getParent();

        if ( ! $parentTransaction) {
            throw new PaymentException('Cannot retrieve parent transaction');
        }

        if ($parentTransaction->status !== TransactionRecord::STATUS_SUCCESS) {
            return new ScalapayRefundResponse([]);
        }

        $orderToken = $parentTransaction->reference;
        $refundAmount = $request->getBodyParam('amount');

        try {
            $response = $this->getClient()->post("payments/{$orderToken}/refund", [
                'json' => [
                    'merchantReference' => $parentTransaction->hash,
                    'refundAmount' => [
                        'amount' => $refundAmount,
                        'currency' => $parentTransaction->currency,
                    ],
                ],
            ]);

        } catch (Exception $e) {
            throw new PaymentException($e->getMessage());
        }

        return new ScalapayRefundResponse([
            'body' => json_decode($response->getBody(), true),
            'status' => 'refund_request',
            'message' => 'Refund request sent to Scalapay',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function processWebHook(): WebResponse
    {
        // @todo Process refund requests from Scalapay merchant panel.
        // return $this->handleWebhookRequest();
    }

    // -------------------------------------------------------------------------
    // PROTECTED METHODS
    // -------------------------------------------------------------------------

    /**
     * Create Scalapay order.
     *
     * @param Transaction $transaction
     * @return RequestResponseInterface
     */
    protected function createScalapayOrder(Transaction $transaction): RequestResponseInterface
    {
        try {
            $response = $this->getClient()->post('orders', [
                'json' => $this->orderBody($transaction),
            ]);

        } catch (Exception $e) {
            throw new PaymentException($e->getMessage());
        }

        return new ScalapayResponse([
            'body' => json_decode($response->getBody(), true),
            'status' => 'created',
            'message' => 'Scalapay: Order created',
        ]);
    }

    /**
     * Capture the payment and finalize the order.
     *
     * @param string $token
     * @param Transaction $transaction
     * @return RequestResponseInterface
     */
    protected function capturePayment(string $token, Transaction $transaction): RequestResponseInterface
    {
        try {
            $response = $this->getClient()->post('payments/capture', [
                'json' => compact('token'),
            ]);

        } catch (Exception $e) {
            throw new PaymentException($e->getMessage());
        }

        return new ScalapayResponse([
            'body' => json_decode($response->getBody(), true),
            'status' => 'charged',
            'message' => 'Scalapay: Payment captured',
        ]);
    }

    /**
     * Body of create order request.
     *
     * @param Transaction $transaction
     * @return array
     */
    protected function orderBody(Transaction $transaction): array
    {
        $order = $transaction->getOrder();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $billingName = $billingAddress->businessName ?: "{$billingAddress->firstName} {$billingAddress->lastName}";
        $shippingName = $shippingAddress->businessName ?: "{$shippingAddress->firstName} {$shippingAddress->lastName}";

        $redirectConfirm = UrlHelper::actionUrl('commerce/payments/complete-payment', [
            'commerceTransactionHash' => $transaction->hash,
        ]);

        $items = [];

        foreach ($order->getLineItems() as $lineItem) {
            $category = '';
            $purchasable = $lineItem->getPurchasable();

            if ($purchasable instanceof Variant) {
                $category = $purchasable->product->tipologia->one()->title ?? '';
            }

            $items[] = [
                'sku' => $lineItem->getSku(),
                'name' => $lineItem->getDescription(),
                'quantity' => $lineItem->qty,
                'category' => $category,
                'price' => [
                    'amount' => strval($lineItem->getTotal()),
                    'currency' => $lineItem->defaultCurrency,
                ],
            ];
        }

        $data = [
            'merchantReference' => $transaction->hash,
            'totalAmount' => [
                'amount' => strval($order->total),
                'currency' => $order->currency,
            ],
            'consumer' => [
                'email' => $order->email,
                'surname' => $billingAddress->lastName,
                'givenNames' => $billingAddress->firstName,
            ],
            'billing' => [
                'name' => $billingName,
                'line1' => $billingAddress->address1,
                'suburb' => $billingAddress->city,
                'postcode' => $billingAddress->zipCode,
                'countryCode' => $billingAddress->countryIso,
                'phoneNumber' => $billingAddress->phone,
            ],
            'shipping' => [
                'name' => $shippingName,
                'line1' => $shippingAddress->address1,
                'suburb' => $shippingAddress->city,
                'postcode' => $shippingAddress->zipCode,
                'countryCode' => $shippingAddress->countryIso,
                'phoneNumber' => $shippingAddress->phone,
            ],
            'items' => $items,
            'merchant' => [
                'redirectCancelUrl' => $order->cancelUrl,
                'redirectConfirmUrl' => UrlHelper::siteUrl($redirectConfirm),
            ],
        ];

        return $data;
    }

    /**
     * Throw an unsupported functionality exception.
     *
     * @throws NotImplementedException
     */
    protected function throwUnsupportedFunctionalityException()
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    // -------------------------------------------------------------------------
    // WEBHOOK METHODS
    // -------------------------------------------------------------------------

    /**
     * Handle webhook request.
     *
     * @return WebResponse
     */
    protected function handleWebhookRequest(): WebResponse
    {
        $response = Craft::$app->getResponse();

        $request = Craft::$app->getRequest();
        $requestStatus = $request->getBodyParam('status');
        $transactionHash = $request->getBodyParam('merchantReference');

        if ( ! $this->validWebhookRequest($request) or $requestStatus !== 'refunded') {
            return $response;
        }

        $commerce = Commerce::getInstance();
        $transaction = $commerce->getTransactions()->getTransactionByHash($transactionHash);

        // Avoid processing the refunds made by the order.
        if ($transaction->status === TransactionRecord::STATUS_SUCCESS) {
            return $response;
        }

        // Check to see if the successful transaction can be refunded.
        if ($transaction and ! $transaction->canRefund()) {
            Craft::warning('Transaction with the hash "'.$transaction->hash.'" cannot be refunded.', 'scalapay');
        }

        // Create refund transaction.
        $refundAmount = $request->getBodyParam('refundAmount', 0);

        try {
            $child = $commerce->getPayments()->refundTransaction($transaction, $refundAmount);

            if ($child->status == TransactionRecord::STATUS_SUCCESS) {
                $child->order->updateOrderPaidInformation();
            }

        } catch (Exception $e) {
            throw new PaymentException($e->getMessage());
        }

        return $response;
    }

    /**
     * Create request validation signature.
     *
     * @param Request $request
     * @return string
     */
    protected function createSignature(Request $request): string
    {
        $payload = json_encode($request->getBodyParams());
        $timestamp = $request->getHeaders()->get('x-scalapay-timestamp');

        $raw = sprintf('%s:%s:%s', 'V1', $timestamp, $payload);

        return hash_hmac('sha256', $raw, $this->getApiKey());
    }

    /**
     * Check if webhook request is valid.
     *
     * @param Request $request
     * @return boolean
     */
    protected function validWebhookRequest(Request $request): bool
    {
        $scalapaySignature = $request->getHeaders()->get('x-scalapay-hmac-v1');

        return $this->createSignature($request) === $scalapaySignature;
    }
}
