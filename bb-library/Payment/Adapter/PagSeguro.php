<?php

/**
 * Pagseguro Payment Gateway
 *
 * @author Erle Carrara
 *
 * Compatível com a versão 2.12 do BoxBilling
 */
class Payment_Adapter_PagSeguro
{
    const API_URL = 'https://ws.pagseguro.uol.com.br/v2/checkout';
    const PAYMENT_FLOW_URL = 'https://pagseguro.uol.com.br/v2/checkout/payment.html?code=';
    const NOTIFICATIONS_URL = 'https://ws.pagseguro.uol.com.br/v2/transactions/notifications/';

    protected $config = array();

    public function __construct($config)
    {
        $this->config = $config;

        if (!function_exists('simplexml_load_string')) {
            throw new Payment_Exception('SimpleXML extension not enabled');
        }

        if (!extension_loaded('curl')) {
            throw new Payment_Exception('cURL extension is not enabled');
        }

        if(empty($this->config['email'])) {
            throw new Exception('Payment gateway "PagSeguro" is not configured properly. Please update configuration parameter "PagSeguro Email" at "Configuration -> Payments".');
        }

        if(empty($this->config['token'])) {
            throw new Exception('Payment gateway "PagSeguro" is not configured properly. Please update configuration parameter "PagSeguro Token" at "Configuration -> Payments".');
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'       =>  false,
            'description'     =>  'PagSeguro Payment Gateway <a href="http://www.pagseguro.com.br">www.pagseguro.com.br</a>. <br /> Desenvolvido por <a href="http://www.zapen.com.br" title="Zapen Desenvolvimento Web">Zapen Desenvolvimento Web</a>',
            'form'  => array(
                'email' => array('text', array(
                        'label' => 'PagSeguro Email',
                 )),
                 'token' => array('text', array(
                    'label' => 'Token',
                 )),
                 'perc_tax' => array('text', array(
                    'label' => 'Taxa Percentual (somente números)'
                 )),
                 'fixe_tax' => array('text', array(
                    'label' => 'Taxa fixa (somente números)'
                 )),
            ),
        );
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(array('id'=>$invoice_id));
        $buyer = $invoice['buyer'];

        if (!empty($buyer['last_name'])) {
            $buyerFullName = $buyer['first_name'] . ' ' . $buyer['last_name'];
        } else {
            $buyerFullName = 'Sr(a). ' . $buyer['first_name']; 
        }

        $xml = new DOMDocument('1.0', 'UTF-8');

        $root = $xml->createElement('checkout');
        $root = $xml->appendChild($root);

        $currency = $xml->createElement('currency', 'BRL');
        $currency = $root->appendChild($currency);

        $items = $xml->createElement('items');
        $items = $root->appendChild($items);

        foreach($invoice['lines'] as $i) {
            $item = $xml->createElement('item');

            $itemId = $xml->createElement('id', $i['id']);
            $itemDescription = $xml->createElement('description', $i['title']);
            $itemAmount = $xml->createElement('amount', ($i['price'] * (($this->config['perc_tax']+100)/100) + $this->config['fixe_tax']));
            $itemQuantity = $xml->createElement('quantity', $i['quantity']);

            $item->appendChild($itemId);
            $item->appendChild($itemDescription);
            $item->appendChild($itemAmount);
            $item->appendChild($itemQuantity);

            $items->appendChild($item);
        }

        $sender = $xml->createElement('sender');
        $sender = $root->appendChild($sender);

        $senderName = $xml->createElement('name', $buyerFullName);
        $senderName = $sender->appendChild($senderName);

        $senderEmail = $xml->createElement('email', $buyer['email']);
        $senderEmail = $sender->appendChild($senderEmail);

        $reference = $xml->createElement('reference', $invoice['id']);
        $reference = $root->appendChild($reference);

        if ($this->config['test_mode']) {
            $xml->formatOutput = true;
        }

        $str = $xml->saveXML();

        $data = array(
            'email' => $this->config['email'],
            'token' => $this->config['token']
        );

        $result = $this->_makeRequest(self::API_URL . '?' . http_build_query($data), $str);

        if (isset($result->code)) {
            $url = self::PAYMENT_FLOW_URL . $result->code;
            return $this->_redirectUser($url);
        } else {
            throw new Exception('Connection to PagSeguro servers failed');
        }
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $code = $data['post']['code'];

        $data = array(
            'email' => $this->getParam('email'),
            'token' => $this->getParam('token')
        );

        $xml = $this->_makeRequest(self::NOTIFICATIONS_URL . $code,
                                   http_query_build($data));

        $invoice_id = $xml->reference;
        $tx = $api_admin->invoice_transaction_get(array('id'=>$id));
        $invoice = $api_admin->invoice_get(array('id'=>$invoice_id));

        $d = array(
            'id'        => $id, 
            'error'     => '',
            'error_code'=> '',
            'status'    => 'pending',
            'updated_at'=> date('c'),
            'amount' => $xml->grossAmount,
            'txn_id' => $xml->code,
            'currency' => $invoice['currency']
        );

        switch ($xml->status) {
            case 3:
            case 4:
                $d['txn_status']  = 'complete';
                $d['status']      = 'complete';
                break;

            default:
                throw new Exception('Unknown PagSeguro Payment status :' . $xml->status);
                break;
        }

        $api_admin->invoice_transaction_update($d);
    }

    protected function _makeRequest($url, $data)
    {
        $headers = array(
            'Content-Type: application/xml; charset=UTF-8',
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        if ($this->config['test_mode']) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_errno($ch) . ' - ' . curl_error($ch));
        }

        return $this->_parseResponse($result);
    }

    protected function _parseResponse($result)
    {
        try {
            $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (Exception $e) {
            throw new Payment_Exception('simpleXmlException: '.$e->getMessage());
        }

        if (isset($xml->error)) {
            throw new Exception($xml->error->code . ': ' . $xml->error->message);
        }

        return $xml;
    }

    protected function _redirectUser($url) {
        $html  = '<h2><a href="'+$url+'">Clique aqui para continuar com o pagamento...</a></h2>';
        $html .= '<script type="text/javascript">';
        $html .= 'setTimeout(function() { window.location.href = "' . $url . '"; }, 3000);';
        $html .= '</script>';
        return $html;
    }
}
