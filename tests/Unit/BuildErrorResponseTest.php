<?php
namespace Getnet\Tests\Unit;

use Exception;
use Getnet\API\BaseResponse;
use Getnet\API\Getnet;
use Getnet\API\GetnetRequestException;
use Getnet\API\Transaction;
use PHPUnit\Framework\TestCase;

class BuildErrorResponseTest extends TestCase {

    private const REQUEST = 'POST https://api.getnet.com.br/v1/payments/credit';

    /**
     * @var Getnet
     */
    private $getnet;

    protected function setUp(): void {
        // O token evita que o construtor bata no /auth.
        $this->getnet = new class ('cid', 'csecret', null, null, 'token-valido') extends Getnet {
            public function erro(Exception $e): BaseResponse {
                return $this->buildErrorResponse($e);
            }
        };
    }

    public function testRecusa402SaiComoDeniedEMantemOCorpoDaGetnet() {
        $corpo = json_encode([
            'status_code' => 402,
            'message' => 'Transaction not authorized',
            'details' => [['status' => 'DENIED', 'description' => 'Saldo insuficiente']],
        ]);

        $r = $this->getnet->erro(new GetnetRequestException('HTTP 402', 402, $corpo, self::REQUEST));

        $this->assertSame(Transaction::STATUS_DENIED, $r->getStatus());
        $this->assertStringContainsString('Saldo insuficiente', $r->getResponseJSON());
        $this->assertSame(402, $r->getStatusCode());
    }

    public function testErro400SaiComoErrorComEnvelopeDiagnostico() {
        $r = $this->getnet->erro(new GetnetRequestException('HTTP 400', 400, '<html>proxy</html>', self::REQUEST));

        $this->assertSame(Transaction::STATUS_ERROR, $r->getStatus());

        $payload = json_decode($r->getResponseJSON(), true);

        $this->assertSame('HTTP 400', $payload['error_message']);
        $this->assertSame('<html>proxy</html>', $payload['raw_response']);
        $this->assertSame(self::REQUEST, $payload['request']);
    }

    public function testTimeoutSaiComoError() {
        $r = $this->getnet->erro(new GetnetRequestException('cURL 28', 0, null, self::REQUEST));

        $this->assertSame(Transaction::STATUS_ERROR, $r->getStatus());
        $this->assertStringContainsString('cURL 28', $r->getErrorMessage());
    }

    /**
     * Regressao: um 2xx com corpo invalido chegava a getStatus() com
     * status_code 201/202 e saia AUTHORIZED de dentro de um catch, aprovando
     * um pedido sem pagamento confirmado.
     *
     * @dataProvider statusDeSucesso
     */
    public function testFalhaComStatusDeSucessoNuncaSaiAprovada($httpCode, $corpo) {
        $r = $this->getnet->erro(new GetnetRequestException('corpo invalido', $httpCode, $corpo, self::REQUEST));

        $this->assertSame(Transaction::STATUS_ERROR, $r->getStatus());
        // getStatus() recalcula a partir do status_code a cada chamada.
        $this->assertSame(Transaction::STATUS_ERROR, $r->getStatus());
    }

    public function statusDeSucesso() {
        return [
            'HTTP 201 com corpo vazio' => [201, ''],
            'HTTP 202 com HTML' => [202, '<html></html>'],
            'HTTP 200 com corpo truncado' => [200, '{"payment_id":'],
        ];
    }

    public function testCorpoDoGatewayComStatusDeSucessoNaoAprovaAFalha() {
        $corpo = json_encode(['status_code' => 200, 'payment_id' => 'abc']);

        $r = $this->getnet->erro(new GetnetRequestException('corpo incoerente', 0, $corpo, self::REQUEST));

        $this->assertSame(Transaction::STATUS_ERROR, $r->getStatus());
    }

    public function testExcecaoQueNaoEDaGetnetVirouEnvelopeComAMensagem() {
        $r = $this->getnet->erro(new Exception('Error select credit or debit'));

        $this->assertSame(Transaction::STATUS_ERROR, $r->getStatus());
        $this->assertStringContainsString('Error select credit or debit', $r->getResponseJSON());
    }

    public function testResponseJsonNuncaEAStringNull() {
        $casos = [
            new GetnetRequestException('sem corpo', 0, null, self::REQUEST),
            new GetnetRequestException('corpo vazio', 500, '', self::REQUEST),
            new Exception('generica'),
        ];

        foreach ($casos as $e) {
            $json = $this->getnet->erro($e)->getResponseJSON();

            $this->assertIsString($json);
            $this->assertNotSame('null', strtolower(trim($json)));
        }
    }

    public function testResponseJsonEStringMesmoComCorpoForaDeUtf8() {
        $r = $this->getnet->erro(
            new GetnetRequestException("binario: \xB1\x31\xC0", 400, "\xB1\x31\xC0", self::REQUEST)
        );

        $this->assertIsString($r->getResponseJSON());
        $this->assertNotSame('null', strtolower(trim($r->getResponseJSON())));
    }

    public function testSetResponseJsonNaoGravaNull() {
        $r = new BaseResponse();
        $r->setResponseJSON(null);

        $this->assertNotSame('null', strtolower(trim($r->getResponseJSON())));
        $this->assertStringContainsString('error_message', $r->getResponseJSON());
    }

}
