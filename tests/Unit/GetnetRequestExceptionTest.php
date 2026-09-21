<?php
namespace Getnet\Tests\Unit;

use Exception;
use Getnet\API\GetnetRequestException;
use PHPUnit\Framework\TestCase;

class GetnetRequestExceptionTest extends TestCase {

    private const REQUEST = 'POST https://api.getnet.com.br/v1/payments/credit';

    public function testDevolveOCorpoOriginalQuandoEJsonValido() {
        $corpo = json_encode([
            'status_code' => 402,
            'message' => 'Transaction not authorized',
            'details' => [['status' => 'DENIED', 'error_code' => 'DENIED']],
        ]);

        $payload = (new GetnetRequestException('recusa', 402, $corpo, self::REQUEST))->toErrorPayload();

        $this->assertSame(402, $payload['status_code']);
        $this->assertSame('Transaction not authorized', $payload['message']);
        $this->assertSame('DENIED', $payload['details'][0]['error_code']);
    }

    public function testMontaEnvelopeQuandoOCorpoNaoEJson() {
        $payload = (new GetnetRequestException('falhou', 400, '<html>proxy</html>', self::REQUEST))->toErrorPayload();

        $this->assertSame('falhou', $payload['error_message']);
        $this->assertSame('<html>proxy</html>', $payload['raw_response']);
        $this->assertSame(self::REQUEST, $payload['request']);
        $this->assertSame(400, $payload['status_code']);
    }

    public function testNaoInjetaStatusCodeSemRespostaHttp() {
        $payload = (new GetnetRequestException('timeout de cURL', 0, null, self::REQUEST))->toErrorPayload();

        $this->assertArrayNotHasKey('status_code', $payload);
        $this->assertSame('timeout de cURL', $payload['error_message']);
    }

    /**
     * Um 2xx com corpo invalido tambem lanca. Injetar esse status faria
     * BaseResponse::getStatus() classificar a falha como AUTHORIZED.
     *
     * @dataProvider statusDeSucesso
     */
    public function testNaoInjetaStatusCodeDeSucesso($httpCode) {
        $payload = (new GetnetRequestException('corpo vazio', $httpCode, '', self::REQUEST))->toErrorPayload();

        $this->assertArrayNotHasKey('status_code', $payload);
    }

    public function statusDeSucesso() {
        return [[200], [201], [202]];
    }

    public function testUsaOCorpoJaDecodificadoSemRedecodificar() {
        $e = new GetnetRequestException('x', 400, '{"nao":"usado"}', self::REQUEST, ['decodificado' => true]);

        $this->assertSame(['decodificado' => true, 'status_code' => 400], $e->toErrorPayload());
    }

    public function testDecodificaSobDemandaQuandoNaoRecebeuOArray() {
        $e = new GetnetRequestException('x', 400, '{"sob":"demanda"}', self::REQUEST);

        $this->assertSame(['sob' => 'demanda'], $e->getDecodedBody());
    }

    public function testWrappingPreservaOContextoDoOriginal() {
        $original = new GetnetRequestException('original', 402, '{"a":1}', self::REQUEST, ['a' => 1]);

        $novo = GetnetRequestException::wrapping('prefixo: original', $original);

        $this->assertSame('prefixo: original', $novo->getMessage());
        $this->assertSame(402, $novo->getHttpCode());
        $this->assertSame('{"a":1}', $novo->getResponseBody());
        $this->assertSame(self::REQUEST, $novo->getRequest());
        $this->assertSame(['a' => 1, 'status_code' => 402], $novo->toErrorPayload());
        $this->assertSame($original, $novo->getPrevious());
    }

    public function testWrappingDeExcecaoGenericaNaoInventaContexto() {
        $original = new Exception('qualquer');

        $novo = GetnetRequestException::wrapping('prefixo: qualquer', $original, self::REQUEST);

        $this->assertSame(0, $novo->getHttpCode());
        $this->assertNull($novo->getResponseBody());
        $this->assertNull($novo->getDecodedBody());
        $this->assertSame(self::REQUEST, $novo->getRequest());
        $this->assertSame($original, $novo->getPrevious());
    }

    public function testGetCodeCarregaOStatusHttp() {
        $this->assertSame(402, (new GetnetRequestException('x', 402))->getCode());
        $this->assertSame(0, (new GetnetRequestException('x'))->getCode());
    }

}
