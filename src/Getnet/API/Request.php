<?php
namespace Getnet\API;

use Exception;

/**
 * Class Request
 *
 * @package Getnet\API
 */
class Request {

    /**
     * Base url from api
     *
     * @var string
     */
    private $baseUrl = '';

    /**
     * Tempo de expiracao do QR Code, em segundos, enviado no header
     * x-qrcode-expiration-time.
     *
     * @var int
     */
    private $qrCodeExpirationTime = 900;

    /**
     * Tempo maximo, em segundos, para estabelecer a conexao TCP/TLS.
     */
    const CONNECT_TIMEOUT = 10;

    /**
     * Tempo maximo, em segundos, para a requisicao inteira.
     */
    const REQUEST_TIMEOUT = 20;

    /**
     * Numero de tentativas para a chamada de autenticacao. Apenas o /auth e
     * reexecutado: e idempotente e nao gera cobranca. Requisicoes de pagamento
     * nunca sao repetidas automaticamente para nao arriscar cobranca duplicada.
     */
    const AUTH_MAX_ATTEMPTS = 3;

    const CURL_TYPE_AUTH   = "AUTH";
    const CURL_TYPE_POST   = "POST";
    const CURL_TYPE_PUT    = "PUT";
    const CURL_TYPE_GET    = "GET";
    const CURL_TYPE_DELETE = "DELETE";

    /**
     * Request constructor.
     *
     * @param Getnet $credentials
     */
    public function __construct(Getnet $credentials) {
        $this->baseUrl = $credentials->getEnvironment()->getApiUrl();
        $this->qrCodeExpirationTime = $credentials->getQrCodeExpirationTime();

        if (!$credentials->getAuthorizationToken()) {
            $this->auth($credentials);
        }
    }

    /**
     *
     * @param Getnet $credentials
     * @return Getnet
     * @throws Exception
     */
    public function auth(Getnet $credentials) {

        if ($this->verifyAuthSession($credentials)) {
            return $credentials;
        }

        $url_path = "/auth/oauth/v2/token";

        $params = [
            "scope" => "oob",
            "grant_type" => "client_credentials"
        ];

        $querystring = http_build_query($params);

        try {
            $response = $this->send($credentials, $url_path, self::CURL_TYPE_AUTH, $querystring);
        } catch (Exception $e) {
            throw new Exception('Falha na autenticacao Getnet: ' . $e->getMessage(), 100, $e);
        }

        if (! isset($response["access_token"])) {
            throw new Exception(
                'Getnet nao retornou access_token: ' . json_encode($response),
                100
            );
        }

        $credentials->setAuthorizationToken($response["access_token"]);
        $credentials->setAuthorizationExpiresIn(
            isset($response["expires_in"]) ? (int) $response["expires_in"] : 0
        );

        //Save auth session
        if ($credentials->getKeySession()) {
            $response['generated'] = microtime(true);
            $_SESSION[$credentials->getKeySession()] = $response;
        }

        return $credentials;
    }

    /**
     * start session for use
     *
     * @param Getnet $credentials
     * @return boolean
     */
    private function verifyAuthSession(Getnet $credentials){

        if ($credentials->getKeySession() && isset($_SESSION[$credentials->getKeySession()]) && $_SESSION[$credentials->getKeySession()]["access_token"]) {

            $auth = $_SESSION[$credentials->getKeySession()];
            $now  = microtime(true);
            $init = $auth["generated"];

            if (($now - $init) < $auth["expires_in"]) {
                $credentials->setAuthorizationToken($auth["access_token"]);

                return true;
            }
        }

        return false;
    }

    /**
     *
     * @param Getnet $credentials
     * @param mixed $url_path
     * @param mixed $method
     * @param mixed $json
     * @throws Exception
     * @return mixed
     * @throws \Exception
     */
    private function send(Getnet $credentials, $url_path, $method, $json = NULL) {
        $url = $this->getFullUrl($url_path);
        $curl = curl_init($url);

        $defaultCurlOptions = array(
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json; charset=utf-8'
            ),
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true
        );

        if ($method == self::CURL_TYPE_POST) {
            $defaultCurlOptions[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $credentials->getAuthorizationToken();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        } elseif ($method == self::CURL_TYPE_GET) {
            $defaultCurlOptions[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $credentials->getAuthorizationToken();
        } elseif ($method == self::CURL_TYPE_DELETE) {
            $defaultCurlOptions[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $credentials->getAuthorizationToken();
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, self::CURL_TYPE_DELETE);
        } elseif ($method == self::CURL_TYPE_PUT) {
            $defaultCurlOptions[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $credentials->getAuthorizationToken();
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, self::CURL_TYPE_PUT);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);

        } elseif ($method == self::CURL_TYPE_AUTH) {
            $defaultCurlOptions[CURLOPT_HTTPHEADER][0] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($curl, CURLOPT_USERPWD, $credentials->getClientId() . ":" . $credentials->getClientSecret());
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }

        // O tempo de expiracao so faz sentido na criacao do QR Code. Antes ele era
        // anexado a todas as requisicoes, inclusive /auth, GET e DELETE.
        if ($method == self::CURL_TYPE_POST) {
            $defaultCurlOptions[CURLOPT_HTTPHEADER][] = 'x-qrcode-expiration-time: ' . $this->qrCodeExpirationTime;
        }

        curl_setopt($curl, CURLOPT_ENCODING, "");
        curl_setopt_array($curl, $defaultCurlOptions);

        $attempts = ($method == self::CURL_TYPE_AUTH) ? self::AUTH_MAX_ATTEMPTS : 1;
        $response = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = curl_exec($curl);

            if ($response !== false) {
                break;
            }

            if ($attempt == $attempts) {
                $errno = curl_errno($curl);
                $error = curl_error($curl);
                $seconds = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
                curl_close($curl);

                throw new Exception(sprintf(
                    'Falha de comunicacao com a Getnet em %s %s apos %d tentativa(s) e %.1fs: [%d] %s',
                    $method,
                    $url,
                    $attempt,
                    $seconds,
                    $errno,
                    $error
                ), 100);
            }

            // backoff progressivo: 200ms, 400ms
            usleep(200000 * $attempt);
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($response, true);

        if (is_array($decoded) && isset($decoded['error'])) {
            $description = isset($decoded['error_description'])
                ? $decoded['error_description']
                : $decoded['error'];

            // A Getnet as vezes devolve estrutura aninhada em vez de string.
            if (! is_scalar($description)) {
                $description = json_encode($description);
            }

            throw new Exception(sprintf(
                'Getnet respondeu erro em %s %s (HTTP %d): %s',
                $method,
                $url,
                $httpCode,
                $description
            ), $httpCode ?: 100);
        }

        if ($httpCode >= 400) {
            throw new Exception(sprintf(
                'Getnet respondeu HTTP %d em %s %s: %s',
                $httpCode,
                $method,
                $url,
                $response
            ), $httpCode);
        }

        // Status code 204 don't have content. That means $response will be always false
        // Provides a custom content for $response to avoid error in the next if logic
        if ($httpCode == 204) {
            return ['status_code' => 204];
        }

        if ($decoded === null) {
            throw new Exception(sprintf(
                'Resposta invalida da Getnet em %s %s (HTTP %d): %s',
                $method,
                $url,
                $httpCode,
                var_export($response, true)
            ), 100);
        }

        return $decoded;
    }

    /**
     * Get request full url
     *
     * @param string $url_path
     * @return string $url(config) + $url_path
     */
    private function getFullUrl($url_path) {
        if (stripos($url_path, $this->baseUrl, 0) === 0) {
            return $url_path;
        }

        return $this->baseUrl . $url_path;
    }

    /**
     *
     * @return string
     */
    public function getBaseUrl() {
        return $this->baseUrl;
    }

    /**
     *
     * @param Getnet $credentials
     * @param mixed $url_path
     * @return mixed
     * * @throws Exception
     */
    public function get(Getnet $credentials, $url_path) {
        return $this->send($credentials, $url_path, self::CURL_TYPE_GET);
    }

    /**
     *
     * @param Getnet $credentials
     * @param mixed $url_path
     * @param mixed $params
     * @return mixed
     * * @throws Exception
     */
    public function post(Getnet $credentials, $url_path, $params) {
        return $this->send($credentials, $url_path, self::CURL_TYPE_POST, $params);
    }

    /**
     *
     * @param Getnet $credentials
     * @param mixed $url_path
     * @param mixed $params
     * @return mixed
     * * @throws Exception
     */
    public function put(Getnet $credentials, $url_path, $params) {
        return $this->send($credentials, $url_path, self::CURL_TYPE_PUT, $params);
    }

    /**
     *
     * @param Getnet $credentials
     * @param mixed $url_path
     * @return mixed
     * * @throws Exception
     */
    public function delete(Getnet $credentials, $url_path) {
        return $this->send($credentials, $url_path, self::CURL_TYPE_DELETE);
    }

}
