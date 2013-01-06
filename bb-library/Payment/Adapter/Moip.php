<?php
/**
 * MoIP Payment Gateway
 *
 * @author Erle Carrara
 *
 * Compatível com a versão 2.12 do BoxBilling
 */

class Payment_Adapter_Moip
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;

        if (!function_exists('simplexml_load_string')) {
            throw new Payment_Exception('SimpleXML extension not enabled');
        }

        if (!extension_loaded('curl')) {
            throw new Payment_Exception('cURL extension is not enabled');
        }

        if(empty($this->config['login'])) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Login" at "Configuration -> Payments".');
        }

        if(empty($this->config['email'])) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Email" at "Configuration -> Payments".');
        }

        if(empty($this->config['token'])) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Token" at "Configuration -> Payments".');
        }

        if(empty($this->config['key'])) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Key" at "Configuration -> Payments".');
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'       =>  false,
            'description'     =>  'MoIP Payment Gateway <a href="http://www.moip.com.br">www.moip.com.br</a>. <br /> Desenvolvido por <a href="http://www.zapen.com.br" title="Desenvolvimento Web">Zapen Desenvolvimento Web</a>',
            'form'  => array(
                'login' => array('text', array(
                        'label' => 'MoIP Login',
                    ),
                 ),
                 'email' => array('text', array(
                    'label' => 'MoIP Email',
                 )),
                 'token' => array('text', array(
                        'label' => 'MoIP Token',
                    ),
                 ),
                 'key' => array('text', array(
                    'label' => 'MoIP Key',
                 )),
                'directPayment' => array('text', array(
                    'label' => 'Direct Payment',
                    'description' => 'S\N'
                )),
                'directPaymentDays' => array('text', array(
                    'label' => 'Direct Payment days to due',
                )),
                'directPaymentLogo' => array('text', array(
                    'label' => 'Logo URL',
                )),
            ),
        );
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(array('id'=>$invoice_id));
        $buyer = $invoice['buyer'];

        $buyer['full_name'] = trim($buyer['first_name'] . ' ' . $buyer['last_name']);

        $xml = new DOMDocument('1.0', 'UTF-8');

        $root = $xml->createElement('EnviarInstrucao');
        $root = $xml->appendChild($root);

        $instr = $xml->createElement('InstrucaoUnica');
        $instr = $root->appendChild($instr);

        $reason = $xml->createElement('Razao', 'Fatura #' . $invoice['nr'] . ' - ' . $invoice['seller']['company']);
        $reason = $instr->appendChild($reason);

        if (strtolower($this->config['directPayment']) == 's') {
            $direct = $xml->createElement('PagamentoDireto');
            $direct = $instr->appendChild($direct);

            $directType = $xml->createElement('Forma', 'BoletoBancario');
            $directType = $direct->appendChild($directType);

            $paymentOptions = $xml->createElement('Boleto');
            $paymentOptions = $instr->appendChild($paymentOptions);

            $paymentDays = $xml->createElement('DiasExpiracao', $this->config['directPaymentDays']);
            $paymentDays->setAttribute('tipo', 'Corridos');
            $paymentDays = $paymentOptions->appendChild($paymentDays);

            $paymentLine1 = $xml->createElement('Instrucao1', 'Não receber após o vencimento');
            $paymentLine1 = $paymentOptions->appendChild($paymentLine1);

            $paymentLogo = $xml->createElement('URLLogo', $this->config['directPaymentLogo']);
            $paymentLogo = $paymentOptions->appendChild($paymentLogo);
        }

        $reference = $xml->createElement('IdProprio', $invoice['id'] . '$' . rand(100, 999));
        $reference = $instr->appendChild($reference);

        $values = $xml->createElement('Valores');
        $values = $instr->appendChild($values);

        $total = $xml->createElement('Valor', $invoice['total']);
        $total = $values->appendChild($total);
        $total->setAttribute('moeda', 'BRL');

        $messages = $xml->createElement('Mensagens');
        $messages = $instr->appendChild($messages);

        foreach ($invoice->lines as $i) {
            $msg = $xml->createElement('Mensagem', $i['title'] . ' - Quant.: ' . $i['quantity']);
            $msg = $messages->appendChild($msg);
        }

        $receiver = $xml->createElement('Recebedor');
        $receiver = $instr->appendChild($receiver);

        $moipLogin = $xml->createElement('LoginMoIP', $this->config['login']);
        $moipLogin = $receiver->appendChild($moipLogin);

        $moipEmail = $xml->createElement('Email', $this->config['email']);
        $moipEmail = $receiver->appendChild($moipEmail);

        $moipNickname = $xml->createElement('Apelido', $this->config['nickname']);
        $moipNickname = $receiver->appendChild($moipNickname);

        $payer = $xml->createElement('Pagador');
        $payer = $instr->appendChild($payer);

        $payerName = $xml->createElement('Nome', $buyer['full_name']);
        $payerName = $payer->appendChild($payerName);

        $payerEmail = $xml->createElement('Email', $buyer['email']);
        $payerEmail = $payer->appendChild($payerEmail);

        $payerAddress = $xml->createElement('EnderecoCobranca');
        $payerAddress = $payer->appendChild($payerAddress);

        $address = array_map('trim', explode(',', $buyer['address']));

        $payerStreet = $xml->createElement('Logradouro', $address[0]);
        $payerStreet = $payerAddress->appendChild($payerStreet);

        $address = array_map('trim', explode(' ', $address[1], 2));

        $payerNu = $xml->createElement('Numero', $address[0]);
        $payerNu = $payerAddress->appendChild($payerNu);

        $payerCity = $xml->createElement('Cidade', $buyer['city']);
        $payerCity = $payerAddress->appendChild($payerCity);

        $payerUf = $xml->createElement('Estado', $buyer['state']);
        $payerUf = $payerAddress->appendChild($payerUf);

        $payerCountry = $xml->createElement('Pais', 'BRA');
        $payerCountry = $payerAddress->appendChild($payerCountry);

        $phone = preg_replace('/[^0-9]+/', '', $buyer['phone']);
        $phone = substr($phone, 2);
        $phone = preg_replace("/([0-9]{2})([0-9]{4})([0-9]{4})/", "($1) $2-$3", $phone);

        $payerPhone = $xml->createElement('TelefoneFixo', $phone);
        $payerPhone = $payerAddress->appendChild($payerPhone);

        $payerZipcode = $xml->createElement('CEP', $buyer['zip']);
        $payerZipcode = $payerAddress->appendChild($payerZipcode);

        $payerNeigh = $xml->createElement('Bairro', $address[1]);
        $payerNeigh = $payerAddress->appendChild($payerNeigh);

        $str = $xml->saveXML();

        if ($this->config['test_mode']) {
            $this->_pay_flow_url = 'https://desenvolvedor.moip.com.br/sandbox/Instrucao.do?token=';
            $this->_endpoint = 'https://desenvolvedor.moip.com.br/sandbox/ws/alpha/EnviarInstrucao/Unica';
        } else {
            $this->_pay_flow_url = 'https://www.moip.com.br/Instrucao.do?token=';
            $this->_endpoint = 'https://www.moip.com.br/ws/alpha/EnviarInstrucao/Unica';
        }

        $response = $this->_makeRequest($this->_endpoint, $str);

        $url = $this->_pay_flow_url . $response->Resposta->Token;
        return $this->_redirectUser($url);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id) {
        $response = $data['post'];

        $invoice_id = explode('$', $response['id_transacao']);
        $tx = $api_admin->invoice_transaction_get(array('id'=>$id));
        $invoice = $api_admin->invoice_get(array('id'=>$invoice_id[0]));

        $d = array(
            'id'        => $id, 
            'error'     => '',
            'error_code'=> '',
            'status'    => 'pending',
            'updated_at'=> date('c'),
            'amount' => intval($response['valor']) / 100,
            'txn_id' => $response['cod_moip'],
            'currency' => $invoice['currency']
        );

        switch ($response['status_pagamento']) {
            case 1:
            case 4:
                $d['txn_status']  = 'complete';
                $d['status']      = 'complete';
                break;

            default:
                throw new Payment_Exception('Unknown MoIP Payment status:' . $response['status_pagamento']);
                break;
        }

        $api_admin->invoice_transaction_update($d);
    }

    private function _makeRequest($url, $data)
    {
        $auth = $this->config['token'] . ":" . $this->config['key'];

        $headers = array(
    		'Content-Type: application/xml; charset=UTF-8',
    		'Authorization: Basic ' . base64_encode($auth)
    	);

    	$ch = curl_init();
    	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    	curl_setopt($ch, CURLOPT_URL, $url);
    	curl_setopt($ch, CURLOPT_USERPWD, $auth);
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    	if ($this->testMode) {
    	    curl_setopt($ch, CURLOPT_VERBOSE, true);
    	}

		$result = curl_exec($ch);

		if (curl_errno($ch)) {
			throw new Payment_Exception('cURL error: ' . curl_errno($ch) . ' - ' . curl_error($ch));
		}

		curl_close($ch);

  		return $this->_parseResponse($result);
    }

    private function _parseResponse($result)
    {
        try {
            $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (Exception $e) {
            throw new Payment_Exception('simpleXmlException: '.$e->getMessage());
        }

        if ($xml->Resposta->Status != 'Sucesso') {
            $errors = array();
            foreach ($xml->Resposta->Erro as $e) {
                $errors[] = $e['Codigo'] . ': ' . $e;
            }
        	throw new Payment_Exception(implode('<br />', $errors));
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
