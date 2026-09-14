<?php
namespace Getnet\API;

/**
 * Class Environment
 * 
 * @package Getnet\API
 */
class Environment {

    private $api;

   /**
    * 
    * @param $api
    */
    private function __construct($api) {
        $this->api = $api;
    }

    /**
     * 
     * @return Environment
     */
    public static function sandbox() {
        return new Environment('https://api-sandbox.getnet.com.br');
    }

    /**
     *
     * @return Environment
     */
    public static function homolog() {
        return new Environment('https://api-homologacao.getnet.com.br');
    }
    
    /**
     *
     * @return Environment
     */
    public static function production() {
        return new Environment('https://api.getnet.com.br');
    }

    /**
     * Ambiente apontando para uma URL arbitrária.
     *
     * Os demais ambientes são endereços fixos da Getnet. Este permite apontar a
     * biblioteca para outro servidor, como um simulador da API usado em
     * desenvolvimento e teste.
     *
     * @param string $url
     * @return Environment
     * @throws \InvalidArgumentException
     */
    public static function custom($url) {
        $url = rtrim(trim((string) $url), '/');

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                'URL de ambiente inválida: ' . var_export($url, true)
            );
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, array('http', 'https'), true)) {
            throw new \InvalidArgumentException(
                'URL de ambiente precisa usar http ou https. Recebido: ' . var_export($scheme, true)
            );
        }

        return new Environment($url);
    }

    /**
     * Gets the environment's Api URL
     *
     * @return string the Api URL
     */
    public function getApiUrl() {
        return $this->api;
    }

}