<?php
namespace Getnet\API;

use Exception;

/**
 * Excecao das chamadas HTTP a Getnet.
 *
 * Carrega o corpo da resposta e o status HTTP separados da mensagem, para que o
 * chamador possa registrar o payload original do gateway sem depender do texto
 * da mensagem ser JSON valido.
 *
 * @package Getnet\API
 */
class GetnetRequestException extends Exception {

    /**
     * Corpo cru devolvido pela Getnet, quando houve resposta.
     *
     * @var string|null
     */
    private $responseBody;

    /**
     * Corpo ja decodificado por quem fez a requisicao, quando era JSON valido.
     *
     * @var array|null
     */
    private $decodedBody;

    /**
     * Status HTTP da resposta. Zero quando a requisicao nao chegou a completar.
     *
     * @var int
     */
    private $httpCode = 0;

    /**
     * Requisicao que falhou, no formato "METODO url".
     *
     * @var string|null
     */
    private $request;

    /**
     * @param string $message
     * @param int $httpCode
     * @param string|null $responseBody
     * @param string|null $request
     * @param array|null $decodedBody
     * @param Exception|null $previous
     */
    public function __construct(
        $message,
        $httpCode = 0,
        $responseBody = null,
        $request = null,
        array $decodedBody = null,
        Exception $previous = null
    ) {
        parent::__construct($message, (int) $httpCode, $previous);

        $this->httpCode = (int) $httpCode;
        $this->responseBody = $responseBody;
        $this->request = $request;
        $this->decodedBody = $decodedBody;
    }

    /**
     * Reembrulha uma falha preservando todo o contexto do original.
     *
     * @param string $message
     * @param Exception $e
     * @param string|null $request usado quando $e nao e uma GetnetRequestException
     * @return self
     */
    public static function wrapping($message, Exception $e, $request = null) {
        if ($e instanceof self) {
            return new self(
                $message,
                $e->getHttpCode(),
                $e->getResponseBody(),
                $e->getRequest(),
                $e->getDecodedBody(),
                $e
            );
        }

        return new self($message, 0, null, $request, null, $e);
    }

    /**
     * @return string|null
     */
    public function getResponseBody() {
        return $this->responseBody;
    }

    /**
     * @return int
     */
    public function getHttpCode() {
        return $this->httpCode;
    }

    /**
     * @return string|null
     */
    public function getRequest() {
        return $this->request;
    }

    /**
     * Corpo da resposta como array, quando era JSON valido.
     *
     * @return array|null
     */
    public function getDecodedBody() {
        if ($this->decodedBody !== null) {
            return $this->decodedBody;
        }

        if ($this->responseBody === null || $this->responseBody === '') {
            return null;
        }

        $decoded = json_decode($this->responseBody, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Payload de erro pronto para ser gravado/registrado.
     *
     * Unico construtor do envelope: devolve o corpo original da Getnet quando
     * ele existe e, quando nao existe, um envelope com o motivo da falha.
     *
     * @return array
     */
    public function toErrorPayload() {
        $body = $this->getDecodedBody();

        if (! is_array($body)) {
            $body = [
                'error_message' => $this->getMessage(),
                'raw_response' => $this->responseBody,
                'request' => $this->request,
            ];
        }

        if ($this->httpCode && ! isset($body['status_code'])) {
            $body['status_code'] = $this->httpCode;
        }

        return $body;
    }

}
