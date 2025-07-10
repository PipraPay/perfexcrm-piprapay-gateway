<?php

defined('BASEPATH') or exit('No direct script access allowed');

class PipraPayApi
{
    private $apiKey;
    private $apiBaseURL;

    public function __construct($apiKey, $apiBaseURL)
    {
        $this->apiKey = $apiKey;
        $this->apiBaseURL = $this->normalizeBaseURL($apiBaseURL);
    }

    public function verifyPayment($pp_id)
    {
        $verifyUrl = $this->decryptSetting('api_url').'/verify-payments';
        $requestData = ['pp_id' => $pp_id];
        return $this->sendRequest('POST', $verifyUrl, $requestData);
    }

    public function executePayment()
    {
        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);
    
        $headers = getallheaders();
    
        $received_api_key = '';
    
        if (isset($headers['mh-piprapay-api-key'])) {
            $received_api_key = $headers['mh-piprapay-api-key'];
        } elseif (isset($headers['Mh-Piprapay-Api-Key'])) {
            $received_api_key = $headers['Mh-Piprapay-Api-Key'];
        } elseif (isset($_SERVER['HTTP_MH_PIPRAPAY_API_KEY'])) {
            $received_api_key = $_SERVER['HTTP_MH_PIPRAPAY_API_KEY']; // fallback if needed
        }
    
        if ($received_api_key !== $this->decryptSetting('api_key')) {
            return;
        }
    
        $pp_id = $data['pp_id'] ?? '';

        return $this->verifyPayment($pp_id);
    }

    private function sendRequest($method, $url, $data)
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'mh-piprapay-api-key: ' . $this->decryptSetting('api_key'),
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("cURL Error: $error");
        }

        return json_decode($response, true);
    }
}


class Piprapay_gateway extends App_gateway
{
    public bool $processingFees = false;

    public function __construct()
    {

        /**
         * Call App_gateway __construct function
         */
        parent::__construct();
        /**
         * REQUIRED
         * Gateway unique id
         * The ID must be alpha/alphanumeric
         */
        $this->setId('piprapay');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('BD Payment Methods');

        /**
         * Add gateway settings
         */
        $this->setSettings([
            [
                'name'      => 'api_key',
                'encrypted' => true,
                'label'     => 'Piprapay API KEY',
            ],
            [
                'name'      => 'api_url',
                'encrypted' => true,
                'label'     => 'Piprapay API URL',
            ],
            [
                'name'          => 'pp_currency_code',
                'encrypted'     => true,
                'label'         => 'Currency Code',
                'default_value' => 'BDT',
            ],
            [
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'Payment for Invoice {invoice_number}',
            ],
        ]);
    }

    /**
     * Process the payment
     *
     * @param  array $data
     *
     * @return mixed
     */
    public function process_payment($data)
    {
        if (is_client_logged_in()) {
            $contact = $this->ci->clients_model->get_contact(get_contact_user_id());
        } else {
            if (total_rows(db_prefix() . 'contacts', ['userid' => $data['invoice']->clientid]) == 1) {
                $contact = $this->ci->clients_model->get_contact(get_primary_contact_user_id($data['invoice']->clientid));
            }
        }

        $amount = number_format($data['amount'], 2, '.', '');
        $currency = $data['invoice']->currency_name;
        $webhookKey = app_generate_hash();
        $returnUrl = site_url('gateways/piprapay/verify_payment?invoiceid=' . $data['invoice']->id . '&hash=' . $data['invoice']->hash);
        $webhookUrl = site_url('gateways/piprapay/webhook/' . $webhookKey);
        $invoiceUrl = site_url('invoice/' . $data['invoice']->id . '/' . $data['invoice']->hash);

        $url = $this->decryptSetting('api_url').'/create-charge';
        
        $data = [
            "full_name" => $contact->firstname . ' ' . $contact->lastname,
            "email_mobile" => $contact->email,
            "amount" => $amount,
            "metadata" => [
                'invoice_id'  => $data['invoice']->id,
                'webhook_key' => $webhookKey,
                'amount' => $amount,
            ],
            "redirect_url" => $returnUrl,
            "return_type" => "GET",
            "cancel_url" => $invoiceUrl,
            "webhook_url" => $webhookUrl,
            "currency" => $this->decryptSetting('pp_currency_code')
        ];
        
        $headers = [
            'accept: application/json',
            'content-type: application/json',
            'mh-piprapay-api-key: '.$this->decryptSetting('api_key')
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);

        curl_close($ch);

        $response_decode = json_decode($response, true);
        
        if(isset($response_decode['pp_url'])){
            redirect($response_decode['pp_url']);
        }else{
            set_alert('danger', "Initialization Error: " . $response);
            redirect($invoiceUrl);
        }
    }

    /**
     * Retrieve payment from Piprapay
     *
     * @param  string $pp_id
     *
     * @return mixed
     */
    public function fetch_payment($pp_id = null)
    {
        try {
            $piprapay = new PipraPayApi($this->decryptSetting('api_key'), $this->decryptSetting('api_url'));
            if ($pp_id) {
                return $piprapay->verifyPayment($pp_id);
            } else {
                return $piprapay->executePayment();
            }
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
