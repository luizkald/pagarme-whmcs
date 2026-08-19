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

    // Timeout por chamada cURL individual. pagarme_storeremote() faz DUAS
    // chamadas sequenciais (createCustomer + createCard) numa única
    // requisição PHP - com 30s cada, o pior caso passava de 60s e estourava
    // o timeout de origem/proxy (Cloudflare 502 "Host Error" observado em
    // produção em 18/08/2026, requisição nunca terminou de processar do
    // lado do cliente, mesmo com o cURL eventualmente concluindo do lado do
    // servidor). 15s por chamada mantém o pior caso de storeremote em ~30s,
    // dentro de limites típicos de PHP-FPM/proxy, e devolve uma recusa clara
    // em vez de deixar o cliente esperando quase um minuto por um 502 sem
    // explicação.
    const REQUEST_TIMEOUT = 15;

    /** @var string Secret Key (produção ou sandbox) */
    private $secretKey;

    /** @var string|null Última mensagem de erro ocorrida */
    private $lastError;

    /** @var array|null Detalhe estruturado do último erro (httpCode/message/errors) */
    private $lastErrorDetails;

    public function __construct($secretKey)
    {
        // Remove espaços/quebras de linha acidentais (comum em copiar/colar),
        // que corromperiam a autenticação HTTP Basic
        $this->secretKey = trim((string) $secretKey);
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
     * Retorna o detalhe estruturado do último erro, para classificação
     * (ver pagarme_classifyDeclineReason() em pagarme.php). Diferente de
     * getLastError() (string já formatada para o Gateway Log), isto preserva
     * os campos originais da resposta da Pagar.me para quem precisar
     * inspecionar `errors` por chave (ex: "card.exp_month") sem re-parsear a
     * string concatenada.
     *
     * @return array|null {httpCode:int|null, message:string|null, errors:array|null}
     */
    public function getLastErrorDetails()
    {
        return $this->lastErrorDetails;
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
        $this->lastError        = null;
        $this->lastErrorDetails = null;

        $ch = curl_init(self::BASE_URL . $path);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
        ));
        // Basic Auth: Secret Key como usuário, senha em branco
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->secretKey . ':');
        curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
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
            $this->lastError        = 'Erro de conexão com a Pagar.me: ' . $curlError;
            $this->lastErrorDetails = array(
                'httpCode' => null,
                'message'  => $this->lastError,
                'errors'   => null,
            );
            return false;
        }

        $decoded = json_decode($responseBody, true);

        if ($httpCode >= 400) {
            $this->lastErrorDetails = array(
                'httpCode' => $httpCode,
                'message'  => isset($decoded['message']) ? $decoded['message'] : null,
                'errors'   => isset($decoded['errors']) && is_array($decoded['errors']) ? $decoded['errors'] : null,
            );

            // Erros de autenticação: mensagem orientativa sobre a Secret Key
            if ($httpCode === 401 || $httpCode === 403) {
                $original = isset($decoded['message']) ? $decoded['message'] : $responseBody;
                $this->lastError = 'Autenticação recusada pela Pagar.me (HTTP ' . $httpCode . '). '
                    . 'Verifique a Secret Key: ela deve começar com "sk_" (produção) ou "sk_test_" '
                    . '(sandbox), sem espaços, e ser da API v5. Não use a chave pública (pk_) nem o '
                    . 'ID da conta (acc_). Resposta original: ' . $original;
                return false;
            }

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
