<?php

declare(strict_types=1);

namespace Avlyalin\SberbankAcquiring\Client;

use Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException;
use Avlyalin\SberbankAcquiring\Models\AcquiringPaymentOperation;
use Avlyalin\SberbankAcquiring\Repositories\AcquiringPaymentStatusRepository;
use Avlyalin\SberbankAcquiring\Traits\HasConfig;
use Avlyalin\SberbankAcquiring\Factories\PaymentsFactory;
use Avlyalin\SberbankAcquiring\Models\AcquiringPayment;
use Avlyalin\SberbankAcquiring\Models\AcquiringPaymentOperationType;
use Avlyalin\SberbankAcquiring\Models\AcquiringPaymentStatus;
use Avlyalin\SberbankAcquiring\Models\AcquiringPaymentSystem;
use Avlyalin\SberbankAcquiring\Repositories\AcquiringPaymentRepository;
use Exception;
use Illuminate\Support\Facades\Auth;
use Throwable;

class Client
{
    use HasConfig;

    /**
     * @var ApiClientInterface
     */
    private $apiClient;
    /**
     * @var PaymentsFactory
     */
    private $paymentsFactory;
    /**
     * @var AcquiringPaymentRepository
     */
    private $acquiringPaymentRepository;
    /**
     * @var AcquiringPaymentStatusRepository
     */
    private $acquiringPaymentStatusRepository;

    /**
     * Client constructor.
     *
     * @param ApiClientInterface $apiClient
     * @param PaymentsFactory $paymentsFactory
     * @param AcquiringPaymentRepository $acquiringPaymentRepository
     * @param AcquiringPaymentStatusRepository $acquiringPaymentStatusRepository
     *
     * @throws Exception
     */
    public function __construct(
        ApiClientInterface $apiClient,
        PaymentsFactory $paymentsFactory,
        AcquiringPaymentRepository $acquiringPaymentRepository,
        AcquiringPaymentStatusRepository $acquiringPaymentStatusRepository
    ) {
        $this->apiClient = $apiClient;
        $this->paymentsFactory = $paymentsFactory;
        $this->acquiringPaymentRepository = $acquiringPaymentRepository;
        $this->acquiringPaymentStatusRepository = $acquiringPaymentStatusRepository;
        $this->apiClient->setBaseUri($this->getConfigBaseURIParam());
    }

    /**
     * ?????????????????????? ????????????
     *
     * @param int $amount    ?????????? ?????????????? ?? ?????????????????????? ???????????????? ????????????
     * @param array $params  ???????????????????????????? ??????????????????
     * @param string $method ?????? HTTP-??????????????
     * @param array $headers ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\AcquiringException
     * @throws \InvalidArgumentException
     * @throws Throwable
     */
    public function register(
        int $amount,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        return $this->performRegister(
            AcquiringPaymentOperationType::REGISTER,
            $amount,
            $params,
            $method,
            $headers
        );
    }

    /**
     * ?????????????????????? ???????????? ?? ????????????????????????????????
     *
     * @param int $amount    ?????????? ?????????????? ?? ?????????????????????? ???????????????? ????????????
     * @param array $params  ???????????????????????????? ??????????????????
     * @param string $method ?????? HTTP-??????????????
     * @param array $headers ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\AcquiringException
     * @throws \InvalidArgumentException
     * @throws Throwable
     */
    public function registerPreAuth(
        int $amount,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        return $this->performRegister(
            AcquiringPaymentOperationType::REGISTER_PRE_AUTH,
            $amount,
            $params,
            $method,
            $headers
        );
    }

    /**
     * ???????????? ???????????????????? ???????????? ????????????
     *
     * @param int $acquiringPaymentId id ???????????? ?????????????? AcquiringPayment
     * @param int $amount             ?????????? ?????????????? ?? ?????????????????????? ???????????????? ????????????
     * @param array $params           ???????????????????????????? ??????????????????
     * @param string $method          ?????? HTTP-??????????????
     * @param array $headers          ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\HttpClientException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\NetworkException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \InvalidArgumentException
     * @throws Throwable
     */
    public function deposit(
        int $acquiringPaymentId,
        int $amount,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        /** @var AcquiringPayment $acquiringPayment */
        $acquiringPayment = $this->acquiringPaymentRepository->findOrFail($acquiringPaymentId);

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'payment_id' => $acquiringPayment->id,
            'user_id' => Auth::id(),
            'type_id' => AcquiringPaymentOperationType::DEPOSIT,
            'request_json' => array_merge([
                'orderId' => $acquiringPayment->bank_order_id,
                'amount' => $amount,
            ], $params),
        ]);
        $operation->saveOrFail();

        $response = $this->apiClient->deposit(
            $acquiringPayment->bank_order_id,
            $amount,
            $this->addAuthParams($params),
            $method,
            $headers
        );

        if ($response->isOk() === false) {
            $acquiringPayment->update(['status_id' => AcquiringPaymentStatus::ERROR]);
        }

        $operationSaved = $operation->update([
            'response_json' => $response->getResponseArray(),
        ]);
        if (!$operationSaved) {
            $responseString = $response->getResponse();
            throw new ResponseProcessingException(
                "Error updating AcquiringPaymentOperation. Response: $responseString"
            );
        }

        return $acquiringPayment;
    }


    /**
     * ???????????? ???????????? ???????????? ????????????
     *
     * @param int $acquiringPaymentId id ???????????? ?????????????? AcquiringPayment
     * @param array $params           ???????????????????????????? ??????????????????
     * @param string $method          ?????? HTTP-??????????????
     * @param array $headers          ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\HttpClientException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\NetworkException
     * @throws Throwable
     */
    public function reverse(
        int $acquiringPaymentId,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        /** @var AcquiringPayment $acquiringPayment */
        $acquiringPayment = $this->acquiringPaymentRepository->findOrFail($acquiringPaymentId);

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'payment_id' => $acquiringPayment->id,
            'user_id' => Auth::id(),
            'type_id' => AcquiringPaymentOperationType::REVERSE,
            'request_json' => array_merge(['orderId' => $acquiringPayment->bank_order_id], $params),
        ]);
        $operation->saveOrFail();

        $response = $this->apiClient->reverse(
            $acquiringPayment->bank_order_id,
            $this->addAuthParams($params),
            $method,
            $headers
        );

        if ($response->isOk()) {
            $acquiringPayment->update(['status_id' => AcquiringPaymentStatus::REVERSED]);
        } else {
            $acquiringPayment->update(['status_id' => AcquiringPaymentStatus::ERROR]);
        }

        $operationSaved = $operation->update([
            'response_json' => $response->getResponseArray(),
        ]);
        if (!$operationSaved) {
            $responseString = $response->getResponse();
            throw new ResponseProcessingException(
                "Error updating AcquiringPaymentOperation. Response: $responseString"
            );
        }

        return $acquiringPayment;
    }

    /**
     * ???????????? ???????????????? ?????????????? ???????????? ????????????
     *
     * @param int $acquiringPaymentId id ???????????? ?????????????? AcquiringPayment
     * @param int $amount             ?????????? ?????????????? ?? ?????????????????????? ???????????????? ????????????
     * @param array $params           ???????????????????????????? ??????????????????
     * @param string $method          ?????? HTTP-??????????????
     * @param array $headers          ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\HttpClientException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\NetworkException
     * @throws Throwable
     */
    public function refund(
        int $acquiringPaymentId,
        int $amount,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        /** @var AcquiringPayment $acquiringPayment */
        $acquiringPayment = $this->acquiringPaymentRepository->findOrFail($acquiringPaymentId);

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'payment_id' => $acquiringPayment->id,
            'user_id' => Auth::id(),
            'type_id' => AcquiringPaymentOperationType::REFUND,
            'request_json' => array_merge([
                'orderId' => $acquiringPayment->bank_order_id,
                'amount' => $amount,
            ], $params),
        ]);
        $operation->saveOrFail();

        $response = $this->apiClient->refund(
            $acquiringPayment->bank_order_id,
            $amount,
            $this->addAuthParams($params),
            $method,
            $headers
        );

        if (!$response->isOk()) {
            $acquiringPayment->update(['status_id' => AcquiringPaymentStatus::ERROR]);
        }

        $operationSaved = $operation->update([
            'response_json' => $response->getResponseArray(),
        ]);
        if (!$operationSaved) {
            $responseString = $response->getResponse();
            throw new ResponseProcessingException(
                "Error updating AcquiringPaymentOperation. Response: $responseString"
            );
        }

        return $acquiringPayment;
    }

    /**
     * ?????????????????? ?????????????? ????????????
     *
     * @param int $acquiringPaymentId id ???????????? ?????????????? AcquiringPayment
     * @param array $params           ??????????????????
     * @param string $method          ?????? HTTP-??????????????
     * @param array $headers          ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\HttpClientException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\NetworkException
     * @throws Throwable
     */
    public function getOrderStatusExtended(
        int $acquiringPaymentId,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        /** @var AcquiringPayment $acquiringPayment */
        $acquiringPayment = $this->acquiringPaymentRepository->findOrFail($acquiringPaymentId);

        $requestParams = array_merge(['orderId' => $acquiringPayment->bank_order_id], $params);

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'payment_id' => $acquiringPayment->id,
            'user_id' => Auth::id(),
            'type_id' => AcquiringPaymentOperationType::GET_EXTENDED_STATUS,
            'request_json' => $requestParams,
        ]);
        $operation->saveOrFail();

        $response = $this->apiClient->getOrderStatusExtended($this->addAuthParams($requestParams), $method, $headers);

        $responseData = $response->getResponseArray();

        if ($response->isOk()) {
            $bankStatusId = (int)$responseData['orderStatus'];
            $orderStatus = $this->acquiringPaymentStatusRepository->findByBankId($bankStatusId);
            if (!$orderStatus) {
                throw new ResponseProcessingException("Unknown \"orderStatus\" \"$bankStatusId\" found in response");
            }
            $acquiringPayment->update(['status_id' => $orderStatus->id]);
        } else {
            $acquiringPayment->update(['status_id' => AcquiringPaymentStatus::ERROR]);
        }

        $operationSaved = $operation->update([
            'response_json' => $responseData,
        ]);
        if (!$operationSaved) {
            $responseString = $response->getResponse();
            throw new ResponseProcessingException(
                "Error updating AcquiringPaymentOperation. Response: $responseString"
            );
        }

        return $acquiringPayment;
    }

    /**
     * ???????????? ???????????? ?????????? Apple Pay
     *
     * @param string $paymentToken ??????????, ???????????????????? ???? ?????????????? Apple Pay
     * @param array $params        ???????????????????????????? ??????????????????
     * @param string $method       ?????? HTTP-??????????????
     * @param array $headers       ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\HttpClientException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\NetworkException
     * @throws Throwable
     */
    public function payWithApplePay(
        string $paymentToken,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        $payment = $this->paymentsFactory->createApplePayPayment();
        $payment->fillWithSberbankParams($params);
        $payment->setPaymentToken($paymentToken);
        $payment->saveOrFail();

        $acquiringPayment = $this->paymentsFactory->createAcquiringPayment();
        $acquiringPayment->fill([
            'system_id' => AcquiringPaymentSystem::APPLE_PAY,
            'status_id' => AcquiringPaymentStatus::NEW,
        ]);
        $acquiringPayment->payment()->associate($payment);
        $acquiringPayment->saveOrFail();

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'user_id' => Auth::id(),
            'type_id' => AcquiringPaymentOperationType::APPLE_PAY_PAYMENT,
            'request_json' => array_merge(['paymentToken' => $paymentToken], $params),
        ]);
        $operation->payment()->associate($acquiringPayment);
        $operation->saveOrFail();

        $merchantLogin = $this->getConfigParam('merchant_login');
        $response = $this->apiClient->payWithApplePay($merchantLogin, $paymentToken, $params, $method, $headers);

        return $this->processResponse($response, $acquiringPayment, $operation);
    }

    /**
     * ???????????? ???????????? ?????????? Samsung Pay
     *
     * @param string $paymentToken ??????????, ???????????????????? ???? ?????????????? Samsung Pay
     * @param array $params        ???????????????????????????? ??????????????????
     * @param string $method       ?????? HTTP-??????????????
     * @param array $headers       ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\HttpClientException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\NetworkException
     * @throws Throwable
     */
    public function payWithSamsungPay(
        string $paymentToken,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        $payment = $this->paymentsFactory->createSamsungPayPayment();
        $payment->fillWithSberbankParams($params);
        $payment->setPaymentToken($paymentToken);
        $payment->saveOrFail();

        $acquiringPayment = $this->paymentsFactory->createAcquiringPayment();
        $acquiringPayment->fill([
            'system_id' => AcquiringPaymentSystem::SAMSUNG_PAY,
            'status_id' => AcquiringPaymentStatus::NEW,
        ]);
        $acquiringPayment->payment()->associate($payment);
        $acquiringPayment->saveOrFail();

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'user_id' => Auth::id(),
            'type_id' => AcquiringPaymentOperationType::SAMSUNG_PAY_PAYMENT,
            'request_json' => array_merge(['paymentToken' => $paymentToken], $params),
        ]);
        $operation->payment()->associate($acquiringPayment);
        $operation->saveOrFail();

        $merchantLogin = $this->getConfigParam('merchant_login');
        $response = $this->apiClient->payWithSamsungPay($merchantLogin, $paymentToken, $params, $method, $headers);

        return $this->processResponse($response, $acquiringPayment, $operation);
    }

    /**
     * ???????????? ???????????? ?????????? Google Pay
     *
     * @param string $paymentToken ??????????, ???????????????????? ???? ?????????????? Google Pay
     * @param int $amount          ?????????? ?????????????? ?? ?????????????????????? ???????????????? ????????????
     * @param array $params        ???????????????????????????? ??????????????????
     * @param string $method       ?????? HTTP-??????????????
     * @param array $headers       ???????????? HTTP-??????????????
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\HttpClientException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\NetworkException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\AcquiringException
     * @throws Throwable
     */
    public function payWithGooglePay(
        string $paymentToken,
        int $amount,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        $returnUrl = $this->getReturnUrl($params);
        unset($params['returnUrl']);

        $failUrl = $this->getFailUrl($params);
        if (!is_null($failUrl)) {
            $params['failUrl'] = $failUrl;
        }

        $fillableParams = array_merge([
            'amount' => $amount,
            'returnUrl' => $returnUrl,
        ], $params);

        $payment = $this->paymentsFactory->createGooglePayPayment();
        $payment->fillWithSberbankParams($fillableParams);
        $payment->setPaymentToken($paymentToken);
        $payment->saveOrFail();

        $acquiringPayment = $this->paymentsFactory->createAcquiringPayment();
        $acquiringPayment->fill([
            'system_id' => AcquiringPaymentSystem::GOOGLE_PAY,
            'status_id' => AcquiringPaymentStatus::NEW,
        ]);
        $acquiringPayment->payment()->associate($payment);
        $acquiringPayment->saveOrFail();

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'user_id' => Auth::id(),
            'type_id' => AcquiringPaymentOperationType::GOOGLE_PAY_PAYMENT,
            'request_json' => array_merge(['paymentToken' => $paymentToken], $fillableParams),
        ]);
        $operation->payment()->associate($acquiringPayment);
        $operation->saveOrFail();

        $response = $this->apiClient->payWithGooglePay(
            $this->getConfigParam('merchant_login'),
            $paymentToken,
            $amount,
            $returnUrl,
            $params,
            $method,
            $headers
        );

        return $this->processResponse($response, $acquiringPayment, $operation);
    }

    /**
     * @param int $operationId
     * @param int $amount
     * @param array $params
     * @param string $method
     * @param array $headers
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\AcquiringException
     * @throws \InvalidArgumentException
     * @throws Throwable
     */
    private function performRegister(
        int $operationId,
        int $amount,
        array $params = [],
        string $method = HttpClientInterface::METHOD_POST,
        array $headers = []
    ): AcquiringPayment {
        $returnUrl = $this->getReturnUrl($params);
        unset($params['returnUrl']);

        $failUrl = $this->getFailUrl($params);
        if (!is_null($failUrl)) {
            $params['failUrl'] = $failUrl;
        }

        $requestData = array_merge(['amount' => $amount, 'returnUrl' => $returnUrl], $params);

        $payment = $this->paymentsFactory->createSberbankPayment();
        $payment->fillWithSberbankParams($requestData);
        $payment->saveOrFail();

        $acquiringPayment = $this->paymentsFactory->createAcquiringPayment();
        $acquiringPayment->fill([
            'system_id' => AcquiringPaymentSystem::SBERBANK,
            'status_id' => AcquiringPaymentStatus::NEW,
        ]);
        $acquiringPayment->payment()->associate($payment);
        $acquiringPayment->saveOrFail();

        $operation = $this->paymentsFactory->createPaymentOperation();
        $operation->fill([
            'user_id' => Auth::id(),
            'type_id' => $operationId,
            'request_json' => $requestData,
        ]);
        $operation->payment()->associate($acquiringPayment);
        $operation->saveOrFail();

        $response = $this->apiClient->register(
            $amount,
            $returnUrl,
            $this->addAuthParams($params),
            $method,
            $headers
        );

        $responseData = $response->getResponseArray();

        $errorMessage = '';

        if ($response->isOk()) {
            $acquiringPaymentSaved = $acquiringPayment->update([
                'bank_order_id' => $responseData['orderId'],
                'status_id' => AcquiringPaymentStatus::REGISTERED,
            ]);

            $paymentSaved = $payment->update(['bank_form_url' => $responseData['formUrl']]);
            if (!$paymentSaved) {
                $errorMessage .= 'Error updating SberbankPayment. ';
            }
        } else {
            $acquiringPaymentSaved = $acquiringPayment->update(['status_id' => AcquiringPaymentStatus::ERROR]);
        }

        if (!$acquiringPaymentSaved) {
            $errorMessage .= 'Error updating AcquiringPayment. ';
        }

        $operationSaved = $operation->update(['response_json' => $responseData]);
        if (!$operationSaved) {
            $errorMessage .= 'Error updating AcquiringPaymentOperation. ';
        }

        if (!empty($errorMessage)) {
            $response = (string)$response->getResponse();
            throw new ResponseProcessingException($errorMessage . "Response: $response");
        }

        return $acquiringPayment;
    }

    /**
     * @param array $params
     *
     * @return array
     * @throws Exception
     */
    private function addAuthParams(array $params = []): array
    {
        $authParams = [];
        $auth = $this->getConfigParam('auth');
        if (empty($auth['userName']) === false && empty($auth['password']) === false) {
            $authParams = [
                'userName' => $auth['userName'],
                'password' => $auth['password'],
            ];
        } elseif (empty($auth['token']) === false) {
            $authParams = ['token' => $auth['token']];
        }
        return array_merge($authParams, $params);
    }

    /**
     * ?????????????????? ????????????
     *
     * @param SberbankResponse $response
     * @param AcquiringPayment $acquiringPayment
     * @param AcquiringPaymentOperation $operation
     *
     * @return AcquiringPayment
     *
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\JsonException
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\ResponseProcessingException
     */
    private function processResponse(
        SberbankResponse $response,
        AcquiringPayment $acquiringPayment,
        AcquiringPaymentOperation $operation
    ): AcquiringPayment {
        $errorMessage = '';

        $responseData = $response->getResponseArray();

        if ($response->isOk()) {
            // ???? ???????????? ???????????? ???????????? ?? ???????????? ???????????????? ????????????????, ??.??. ???? ??.??. ????????????
            $acquiringPaymentSaved = $acquiringPayment->update([
                'bank_order_id' => $responseData['data']['orderId'],
            ]);
        } else {
            $acquiringPaymentSaved = $acquiringPayment->update(['status_id' => AcquiringPaymentStatus::ERROR]);
        }

        if (!$acquiringPaymentSaved) {
            $errorMessage .= 'Error updating AcquiringPayment. ';
        }

        $operationSaved = $operation->update(['response_json' => $responseData]);
        if (!$operationSaved) {
            $errorMessage .= 'Error updating AcquiringPaymentOperation. ';
        }

        if (!empty($errorMessage)) {
            $response = (string)$response->getResponse();
            throw new ResponseProcessingException($errorMessage . "Response: $response");
        }

        return $acquiringPayment;
    }

    /**
     * ???????????????????? ???????????????? returnUrl
     *
     * @param array $params
     *
     * @return string|null
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\AcquiringException
     */
    private function getReturnUrl(array $params): string
    {
        return $params['returnUrl'] ?? $this->getConfigParam('params.return_url');
    }

    /**
     * ???????????????????? ???????????????? failUrl
     *
     * @param array $params
     *
     * @return string|null
     * @throws \Avlyalin\SberbankAcquiring\Exceptions\AcquiringException
     */
    private function getFailUrl(array $params): ?string
    {
        $configFailUrl = $this->getConfigParam('params.fail_url');
        if (isset($params['failUrl'])) {
            return $params['failUrl'];
        } elseif (!empty($configFailUrl)) {
            return $configFailUrl;
        }
        return null;
    }
}
