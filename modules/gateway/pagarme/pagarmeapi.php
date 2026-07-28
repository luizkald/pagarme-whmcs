<?php
/**
 * Cliente HTTP para a API Core v5 da Pagar.me
 *
 * Documentação: https://docs.pagar.me/reference/introducao-2
 *
 * Autenticação: HTTP Basic, usando a Secret Key como usuário e senha em branco.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class PagarmeApi
{
    const BASE_URL = 'https://api.pagar.me/core/v5';

    /** @var string Secret Key (produção ou sandbox) */
    private $secretKey;

    /** @var string|null Última mensagem de erro ocorrida */
    private $lastError;

    public function __construct($secretKey)
    {
        $this->secretKey = $secretKey;
    }

    // ---------------------------------------------------------------------
    // Pedidos e cobranças
    // ---------------------------------------------------------------------

    /**
     * Cria um pedido (order) com um pagamento associado
     *
     * @param array $payload Corpo da requisição conforme a API v5
     * @return array|false
     */
    public function createOrder(array $payload)
    {
        return $this->request('POST', '/orders', $payload);
    }

    /**
     * Consulta um pedido pelo ID
     *
     * @param string $orderId
     * @return array|false
     */
    public function getOrder($orderId)
    {
        return $this->request('GET', '/orders/' . $orderId);
    }

    /**
     * Cancela / estorna uma cobrança
     *
     * @param string $chargeId
     * @return array|false
     */
    public function cancelCharge($chargeId)
    {
        return $this->request('DELETE', '/charges/' . $chargeId);
    }

    // ---------------------------------------------------------------------
    // Clientes e cartões salvos (tokenização)
    // ---------------------------------------------------------------------

    /**
     * Cria um cliente na Pagar.me (pré-requisito para salvar um cartão)
     *
     * @param array $payload
     * @return array|false
     */
    public function createCustomer(array $payload)
    {
        return $this->request('POST', '/customers', $payload);
    }

    /**
     * Salva um cartão vinculado a um cliente existente (tokenização)
     *
     * @param string $customerId
     * @param array  $payload
     * @return array|false
     */
    public function createCard($customerId, array $payload)
    {
        return $this->request('POST', '/customers/' . $customerId . '/cards', $payload);
    }

    /**
     * Remove (desativa) um cartão salvo de um cliente
     *
     * @param string $customerId
     * @param string $cardId
     * @return array|false
     */
    public function deleteCard($customerId, $cardId)
    {
        return $this->request('DELETE', '/customers/' . $customerId . '/cards/' . $cardId);
    }

    // ---------------------------------------------------------------------
    // Infraestrutura
    // ---------------------------------------------------------------------

    /**
     * Retorna a última mensagem de erro registrada
     *
     * @return string|null
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Executa a requisição HTTP contra a API da Pagar.me
     *
     * @param string     $method  GET, POST ou DELETE
     * @param string     $path    Caminho relativo (ex: /orders)
     * @param array|null $payload Corpo da requisição, quando aplicável
     * @return array|false Array decodificado em caso de sucesso, false em caso de erro
     */
    private function request($method, $path, array $payload = null)
    {
        $this->lastError = null;

        $ch = curl_init(self::BASE_URL . $path);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
        ));
        // Basic Auth: Secret Key como usuário, senha em branco
        curl_setopt($ch, CURLOPT_USERPWD, $this->secretKey . ':');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->lastError = 'Erro de conexão com a Pagar.me: ' . $curlError;
            return false;
        }

        $decoded = json_decode($responseBody, true);

        if ($httpCode >= 400) {
            if (isset($decoded['message'])) {
                $this->lastError = $decoded['message'];
                if (!empty($decoded['errors'])) {
                    $this->lastError .= ' | Detalhes: ' . json_encode($decoded['errors']);
                }
            } elseif (isset($decoded['errors'])) {
                $this->lastError = json_encode($decoded['errors']);
            } else {
                $this->lastError = 'Erro HTTP ' . $httpCode . ': ' . $responseBody;
            }
            return false;
        }

        if ($decoded === null && $responseBody !== '') {
            $this->lastError = 'Resposta inválida da Pagar.me: ' . $responseBody;
            return false;
        }

        // DELETE pode retornar corpo vazio com sucesso
        return is_array($decoded) ? $decoded : array();
    }
}
