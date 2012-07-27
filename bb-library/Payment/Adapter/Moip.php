<?php
/**
 * MoIP Payment Gateway
 *
 * @author Erle Carrara
 */
class Payment_Adapter_Moip extends Payment_AdapterAbstract
{

    public function init()
    {
        if (!function_exists('simplexml_load_string')) {
        	throw new Payment_Exception('SimpleXML extension not enabled');
        }

        if (!extension_loaded('curl')) {
            throw new Payment_Exception('cURL extension is not enabled');
        }

        if(!$this->getParam('login')) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Login" at "Configuration -> Payments".');
        }

        if(!$this->getParam('email')) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Email" at "Configuration -> Payments".');
        }

        if(!$this->getParam('token')) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Token" at "Configuration -> Payments".');
        }

        if(!$this->getParam('key')) {
            throw new Payment_Exception('Payment gateway "MoIP" is not configured properly. Please update configuration parameter "MoIP Key" at "Configuration -> Payments".');
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'       =>  false,
            'description'     =>  'MoIP Payment Gateway <a href="http://www.moip.com.br">www.moip.com.br</a>. <br /> Desenvolvido por <a href="http://www.ewchost.com" title="Hospedagem de Site">EWC Host</a>',
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

    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_FORM;
    }

    public function getServiceUrl()
    {
        return $this->url;
    }

    public function singlePayment(Payment_Invoice $invoice)
    {
        $buyer = $invoice->getBuyer();

        $buyerName = $buyer->getFirstName();
        $buyerLastName = $buyer->getLastname();

        $buyerFullName = $buyerName;
        if (!empty($buyerLastName)) {
            $buyerFullName .= ' ' . $buyerLastName;
        }

        $xml = new DOMDocument('1.0', 'UTF-8');

        $root = $xml->createElement('EnviarInstrucao');
        $root = $xml->appendChild($root);

        $instr = $xml->createElement('InstrucaoUnica');
        $instr = $root->appendChild($instr);

        $reason = $xml->createElement('Razao', $invoice->getTitle());
        $reason = $instr->appendChild($reason);

        if (strtolower($this->getParam('directPayment')) == 's') {
            $direct = $xml->createElement('PagamentoDireto');
            $direct = $instr->appendChild($direct);

            $directType = $xml->createElement('Forma', 'BoletoBancario');
            $directType = $direct->appendChild($directType);

            $paymentOptions = $xml->createElement('Boleto');
            $paymentOptions = $instr->appendChild($paymentOptions);

            $paymentDays = $xml->createElement('DiasExpiracao', $this->getParam('directPaymentDays'));
            $paymentDays->setAttribute('tipo', 'Corridos');
            $paymentDays = $paymentOptions->appendChild($paymentDays);

            $paymentLine1 = $xml->createElement('Instrucao1', 'Não receber após o vencimento');
            $paymentLine1 = $paymentOptions->appendChild($paymentLine1);

            $paymentLogo = $xml->createElement('URLLogo', $this->getParam('directPaymentLogo'));
            $paymentLogo = $paymentOptions->appendChild($paymentLogo);
        }

        $reference = $xml->createElement('IdProprio', $invoice->getNumber()  . '.' . $invoice->getId() . '.' . rand(0, 999));
        $reference = $instr->appendChild($reference);

        $values = $xml->createElement('Valores');
        $values = $instr->appendChild($values);

        $total = $xml->createElement('Valor', $invoice->getTotal());
        $total = $values->appendChild($total);
        $total->setAttribute('moeda', 'BRL');

        $messages = $xml->createElement('Mensagens');
        $messages = $instr->appendChild($messages);

        foreach ($invoice->getItems() as $i) {
            $msg = $xml->createElement('Mensagem', $i->getDescription() . ' - Quant.: ' . $i->getQuantity());
            $msg = $messages->appendChild($msg);
        }

        $receiver = $xml->createElement('Recebedor');
        $receiver = $instr->appendChild($receiver);

        $moipLogin = $xml->createElement('LoginMoIP', $this->getParam('login'));
        $moipLogin = $receiver->appendChild($moipLogin);

        $moipEmail = $xml->createElement('Email', $this->getParam('email'));
        $moipEmail = $receiver->appendChild($moipEmail);

        $moipNickname = $xml->createElement('Apelido', $this->getParam('nickname'));
        $moipNickname = $receiver->appendChild($moipNickname);

        $payer = $xml->createElement('Pagador');
        $payer = $instr->appendChild($payer);

        $payerName = $xml->createElement('Nome', $buyerFullName);
        $payerName = $payer->appendChild($payerName);

        $payerEmail = $xml->createElement('Email', $buyer->getEmail());
        $payerEmail = $payer->appendChild($payerEmail);

        $payerAddress = $xml->createElement('EnderecoCobranca');
        $payerAddress = $payer->appendChild($payerAddress);

        $address = array_map('trim', explode(',', $buyer->getAddress()));

        $payerStreet = $xml->createElement('Logradouro', $address[0]);
        $payerStreet = $payerAddress->appendChild($payerStreet);

        $address = array_map('trim', explode(' ', $address[1], 2));

        $payerNu = $xml->createElement('Numero', $address[0]);
        $payerNu = $payerAddress->appendChild($payerNu);

        $payerCity = $xml->createElement('Cidade', $buyer->getCity());
        $payerCity = $payerAddress->appendChild($payerCity);

        $payerUf = $xml->createElement('Estado', $buyer->getState());
        $payerUf = $payerAddress->appendChild($payerUf);

        $payerCountry = $xml->createElement('Pais', 'BRA');
        $payerCountry = $payerAddress->appendChild($payerCountry);

        $phone = preg_replace('/[^0-9]+/', '', $buyer->getPhone());
        $phone = substr($phone, 2);
        $phone = preg_replace("/([0-9]{2})([0-9]{4})([0-9]{4})/", "($1) $2-$3", $phone);

        $payerPhone = $xml->createElement('TelefoneFixo', $phone);
        $payerPhone = $payerAddress->appendChild($payerPhone);

        $payerZipcode = $xml->createElement('CEP', $buyer->getZip());
        $payerZipcode = $payerAddress->appendChild($payerZipcode);

        $payerNeigh = $xml->createElement('Bairro', $address[1]);
        $payerNeigh = $payerAddress->appendChild($payerNeigh);

        $str = $xml->saveXML();

        if ($this->getTestMode()) {
            $this->_pay_flow_url = 'https://desenvolvedor.moip.com.br/sandbox/Instrucao.do?token=';
            $this->_endpoint = 'https://desenvolvedor.moip.com.br/sandbox/ws/alpha/EnviarInstrucao/Unica';
        } else {
            $this->_pay_flow_url = 'https://www.moip.com.br/Instrucao.do?token=';
            $this->_endpoint = 'https://www.moip.com.br/ws/alpha/EnviarInstrucao/Unica';
        }

        $response = $this->_makeRequest($this->_endpoint, $str);

        $this->url = $this->_pay_flow_url . $response->Resposta->Token;
    }

    public function recurrentPayment(Payment_Invoice $invoice)
    {
        // not implemented
    }

    public function isIpnValid($data, Payment_Invoice $invoice)
    {
        return true;
    }

    public function getInvoiceId($data)
    {
        $id = parent::getInvoiceId($data);
        if(!is_null($id)) {
            return $id;
        }

        $reference = explode('.', $data['post']['id_transacao']);
        return intval($reference[1]);
    }

    public function getTransaction($data, Payment_Invoice $invoice)
    {
        $response = $data['post'];

        $tx = new Payment_Transaction();
        $tx->setId($response['cod_moip']);
		$tx->setAmount(intval($response['valor']) / 100);
		$tx->setCurrency('BRL');
        $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);

        switch ($response['status_pagamento']) {
            case 1:
            case 4:
                $tx->setStatus(Payment_Transaction::STATUS_COMPLETE);
                break;

            default:
                throw new Payment_Exception('Unknown MoIP Payment status:' . $response['status_pagamento']);
                break;
        }

        return $tx;
    }

    private function _makeRequest($url, $data)
    {
        $auth = $this->getParam('token') . ":" . $this->getParam('key');

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
}
