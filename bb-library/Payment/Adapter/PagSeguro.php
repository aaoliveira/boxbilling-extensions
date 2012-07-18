<?php
/**
 * Pagseguro Payment Gateway
 *
 * @author Erle Carrara
 */
class Payment_Adapter_PagSeguro extends Payment_AdapterAbstract
{
    const API_URL = 'https://ws.pagseguro.uol.com.br/v2/checkout';
    const PAYMENT_FLOW_URL = 'https://pagseguro.uol.com.br/v2/checkout/payment.html?code=';
    const NOTIFICATIONS_URL = 'https://ws.pagseguro.uol.com.br/v2/transactions/notifications/';

    public function init()
    {
        if (!function_exists('simplexml_load_string')) {
        	throw new Payment_Exception('SimpleXML extension not enabled');
        }

        if (!extension_loaded('curl')) {
            throw new Payment_Exception('cURL extension is not enabled');
        }

        if(!$this->getParam('email')) {
            throw new Payment_Exception('Payment gateway "PagSeguro" is not configured properly. Please update configuration parameter "PagSeguro Email" at "Configuration -> Payments".');
        }

        if(!$this->getParam('token')) {
            throw new Payment_Exception('Payment gateway "PagSeguro" is not configured properly. Please update configuration parameter "PagSeguro Token" at "Configuration -> Payments".');
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'       =>  false,
            'description'     =>  'PagSeguro Payment Gateway <a href="http://www.pagseguro.com.br">www.pagseguro.com.br</a>. <br /> Desenvolvido por <a href="http://www.ewchost.com" title="Hospedagem de Site">EWC Host</a>',
            'form'  => array(
                'email' => array('text', array(
                        'label' => 'PagSeguro Email',
                    ),
                 ),
                 'token' => array('text', array(
                    'label' => 'Token',
                 ))
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

        $root = $xml->createElement('checkout');
        $root = $xml->appendChild($root);

        $currency = $xml->createElement('currency', 'BRL');
        $currency = $root->appendChild($currency);

        $items = $xml->createElement('items');
        $items = $root->appendChild($items);

        foreach($invoice->getItems() as $i) {
			$item = $xml->createElement('item');

			$itemId = $xml->createElement('id', $i->getId());
			$itemDescription = $xml->createElement('description', $i->getDescription());
			$itemAmount = $xml->createElement('amount', $i->getPrice());
			$itemQuantity = $xml->createElement('quantity', $i->getQuantity());

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

		$senderEmail = $xml->createElement('email', $buyer->getEmail());
		$senderEmail = $sender->appendChild($senderEmail);

		$reference = $xml->createElement('reference', $invoice->getNumber()  . '.' . $invoice->getId());
		$reference = $root->appendChild($reference);

        $xml->formatOutput = true;
        $str = $xml->saveXML();

        $data = array(
            'email' => $this->getParam('email'),
            'token' => $this->getParam('token')
        );

        $result = $this->_makeRequest(self::API_URL . '?' . http_build_query($data), $str);

        if (isset($result->code)) {
            $this->url =self::PAYMENT_FLOW_URL . $result->code;
            return true;
        } else {
            throw new Payment_Exception('Connection to PagSeguro servers failed');
        }

        return false;
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

        $reference = explode('.', $data['post']['reference']);
        return intval($reference[1]);
    }

    public function getTransaction($data, Payment_Invoice $invoice)
    {
        $code = $data['post']['code'];

        $data = array(
            'email' => $this->getParam('email'),
            'token' => $this->getParam('token')
        );

        $xml = $this->_makeRequest(self::NOTIFICATIONS_URL . $code,
                                   http_query_build($data));

        $tx = new Payment_Transaction();
        $tx->setId($xml->code);
		$tx->setAmount($xml->grossAmount);
		$tx->setCurrency($invoice->getCurrency());
        $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);

        switch ($xml->status) {
            case 3:
            case 4:
                $tx->setStatus(Payment_Transaction::STATUS_COMPLETE);
                break;

            default:
                throw new Payment_Exception('Unknown PagSeguro Payment status :' . $xml->status);
                break;
        }

        return $tx;
    }

    private function _makeRequest($url, $data)
    {
        $headers = array(
    		'Content-Type: application/xml; charset=UTF-8',
    	);

    	$ch = curl_init();
    	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    	curl_setopt($ch, CURLOPT_URL, $url);
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

  		return $this->_parseResponse($result);
    }

    private function _parseResponse($result)
    {
        try {
            $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (Exception $e) {
            throw new Payment_Exception('simpleXmlException: '.$e->getMessage());
        }

        if (isset($xml->error)) {
        	throw new Payment_Exception($xml->error->code . ': ' . $xml->error->message);
        }

        return $xml;
    }
}
