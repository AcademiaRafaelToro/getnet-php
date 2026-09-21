<?php
namespace Getnet\API;

/**
 * Class Getnet
 *
 * @package Getnet\API
 */
class Getnet {

    private $client_id;

    private $client_secret;

    private $environment;

    private $authorizationToken;

    private $keySession;

    /**
     * Validade em segundos do token retornado pelo /auth. Permite que o
     * consumidor cacheie o token externamente em vez de reautenticar a cada
     * transacao.
     *
     * @var int
     */
    private $authorizationExpiresIn = 0;

    /**
     * Tempo de expiracao do QR Code Pix, em segundos.
     *
     * @var int
     */
    private $qrCodeExpirationTime = 900;
    

    /**
     * 
     * @param mixed $client_id
     * @param mixed $client_secret
     * @param mixed $env
     * @return Getnet
     */
    public function __construct($client_id, $client_secret, Environment $environment = null, $keySession = null, $authorizationToken = null) {
        
        if (!$environment) {
            $environment = Environment::production();
        }
        
        $this->setClientId($client_id);
        $this->setClientSecret($client_secret);
        $this->setEnvironment($environment);
        $this->setKeySession($keySession);

        // Com um token ja valido em maos nao ha motivo para bater no /auth.
        if ($authorizationToken) {
            $this->setAuthorizationToken($authorizationToken);

            return;
        }

        $request = new Request($this);
        $request->auth($this);
    }
    
    /**
     * @return \Getnet\API\Request
     */
    public function getClientId() {
        return $this->client_id;
    }

    /**
     * @param \Getnet\API\Request $client_id
     */
    public function setClientId($client_id) {
        $this->client_id = (string)$client_id;
        
        return $this;
    }

    /**
     * @return mixed
     */
    public function getClientSecret() {
        return $this->client_secret;
    }

    /**
     * @param mixed $client_secret
     */
    public function setClientSecret($client_secret) {
        $this->client_secret = (string)$client_secret;
        
        return $this;
    }

    /**
     * @return Environment
     */
    public function getEnvironment() {
        return $this->environment;
    }

    /**
     * @param string $environment
     */
    public function setEnvironment(Environment $environment) {
        $this->environment = $environment;
        
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAuthorizationToken() {
        return $this->authorizationToken;
    }

    /**
     * @param mixed $authorizationToken
     */
    public function setAuthorizationToken($authorizationToken) {
        $this->authorizationToken = (string)$authorizationToken;
        
        return $this;
    }
    
    /**
     * @return mixed
     */
    public function getKeySession() {
        return $this->keySession;
    }
    
    /**
     * @param mixed $keySession
     */
    public function setKeySession($keySession) {
        $this->keySession = (string)$keySession;
    }

    /**
     * @return int
     */
    public function getAuthorizationExpiresIn() {
        return $this->authorizationExpiresIn;
    }

    /**
     * @param int $expiresIn
     * @return Getnet
     */
    public function setAuthorizationExpiresIn($expiresIn) {
        $this->authorizationExpiresIn = (int)$expiresIn;

        return $this;
    }

    /**
     * @return int
     */
    public function getQrCodeExpirationTime() {
        return $this->qrCodeExpirationTime;
    }

    /**
     * @param int $seconds
     * @return Getnet
     */
    public function setQrCodeExpirationTime($seconds) {
        $this->qrCodeExpirationTime = (int)$seconds;

        return $this;
    }

    /**
     * Monta a resposta de erro preservando o payload original da Getnet.
     *
     * Antes cada catch fazia json_decode($e->getMessage()): como a mensagem
     * deixou de ser JSON cru, o decode devolvia null e o responseJSON era
     * gravado como a string "null", sem nenhum motivo da falha.
     *
     * @param \Exception $e
     * @return BaseResponse
     */
    protected function buildErrorResponse(\Exception $e) {
        $error = new BaseResponse();

        $body = ($e instanceof GetnetRequestException)
            ? $e->toErrorPayload()
            : ['error_message' => $e->getMessage()];

        $error->mapperJson($body);
        $error->setErrorMessage($e->getMessage());

        // getStatus() deriva o status do status_code a cada chamada, e
        // sobrescreve o que setStatus() tenha gravado. Uma resposta montada
        // num catch nunca pode sair AUTHORIZED ou PENDING, entao o status_code
        // que nao classifique a falha e descartado antes de forcar ERROR.
        if (! in_array($error->getStatus(), [Transaction::STATUS_DENIED, Transaction::STATUS_ERROR], true)) {
            $error->setStatusCode(null);
            $error->setStatus(Transaction::STATUS_ERROR);
        }

        return $error;
    }

    /**
     *
     * @param Transaction $transaction
     * @return BaseResponse|AuthorizeResponse
     */
    public function authorize(Transaction $transaction) {
        try {

            $request = new Request($this);

            if ($transaction->getCredit()) {
                $response = $request->post($this, "/v1/payments/credit", $transaction->toJSON());
            } elseif ($transaction->getDebit()) {
                $response = $request->post($this, "/v1/payments/debit", $transaction->toJSON());
            }else{
                throw new \Exception("Error select credit or debit");
            }
        } catch (\Exception $e) {

            return $this->buildErrorResponse($e);
        }

        $authresponse = new AuthorizeResponse();
        $authresponse->mapperJson($response);

        return $authresponse;
    }

    /**
     * 
     * @param mixed $payment_id
     * @return BaseResponse|AuthorizeResponse
     */
    public function authorizeConfirm($payment_id) {
        try {
            $request = new Request($this);
            $response = $request->post($this, "/v1/payments/credit/".$payment_id."/confirm", "");
        } catch (\Exception $e) {

            return $this->buildErrorResponse($e);
        }
        
        $authresponse = new AuthorizeResponse();
        $authresponse->mapperJson($response);

        return $authresponse;
    }

    /**
     * 
     * @param mixed $payment_id
     * @param mixed $payer_authentication_response
     * @return BaseResponse|AuthorizeResponse
     */
    public function authorizeConfirmDebit($payment_id, $payer_authentication_response) {
        try {
            $payer_authentication_response = array("payer_authentication_response" => $payer_authentication_response);
            $request = new Request($this);
            $response = $request->post($this, "/v1/payments/debit/".$payment_id."/authenticated/finalize", json_encode($payer_authentication_response));
        } catch (\Exception $e) {

            return $this->buildErrorResponse($e);
        }
        
        $authresponse = new AuthorizeResponse();
        $authresponse->mapperJson($response);

        return $authresponse;
    }

    /**
     * Estorna ou desfaz transações feitas no mesmo dia (D0).
     *
     * @param $payment_id
     * @param $amount_val
     * @return AuthorizeResponse|BaseResponse
     */
    public function authorizeCancel($payment_id, $amount_val) {
        $amount = array("amount" => $amount_val);

        try {
            $request = new Request($this);
            $response = $request->post($this, "/v1/payments/credit/".$payment_id."/cancel", json_encode($amount));
        } catch (\Exception $e) {

            return $this->buildErrorResponse($e);
        }
        
        $authresponse = new AuthorizeResponse();
        $authresponse->mapperJson($response);

        return $authresponse;
    }

    /**
     * Solicita o cancelamento de transações que foram realizadas há mais de 1 dia (D+n).
     * 
     * @param mixed $payment_id
     * @param mixed $cancel_amount
     * @param mixed $cancel_custom_key
     * @return AuthorizeResponse|BaseResponse
     */
    public function cancelTransaction($payment_id, $cancel_amount, $cancel_custom_key) {
        
        $params = array("payment_id"=>$payment_id, "cancel_amount"=>$cancel_amount, "cancel_custom_key"=>$cancel_custom_key);

        try {
            $request = new Request($this);
            $response = $request->post($this, "/v1/payments/cancel/request", json_encode($params));
        } catch (\Exception $e) {

            return $this->buildErrorResponse($e);
        }
        
        $authresponse = new AuthorizeResponse();
        $authresponse->mapperJson($response);

        return $authresponse;
    }

    /**
     *
     * @param Transaction $transaction
     * @return BaseResponse|BoletoRespose
     */
    public function boleto(Transaction $transaction) {
        try {
            $request = new Request($this);
            $response = $request->post($this, "/v1/payments/boleto", $transaction->toJSON());
            
            $boletoresponse = new BoletoRespose();
            $boletoresponse->mapperJson($response);
            $boletoresponse->setBaseUrl($request->getBaseUrl());
            $boletoresponse->generateLinks();
    
            return $boletoresponse;
        } catch (\Exception $e) {

            return $this->buildErrorResponse($e);
        }
    }

    /**
     *
     * Diferente dos demais, propaga a excecao: antes ela virava um PixResponse
     * todo nulo, que o chamador interpretava como sucesso.
     *
     * @param Transaction $transaction
     * @return PixResponse
     */
    public function pix(Transaction $transaction) {
        $request = new Request($this);
        $response = $request->post($this, "/v1/payments/qrcode/pix", json_encode([
            "amount" => $transaction->getAmount(),
            "currency" => $transaction->getCurrency(),
            "order_id" => $transaction->getOrder()->getOrderId(),
            "customer_id" => $transaction->getCustomer()->getCustomerId(),
        ]));

        $pixresponse = new PixResponse();
        $pixresponse->mapperJson($response);

        return $pixresponse;
    }


}