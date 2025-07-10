<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Piprapay extends App_Controller
{
    /**
     * Show message to the customer whether the payment is successfully
     *
     * @return mixed
     */
    public function verify_payment()
    {
        $pp_id = $this->input->get('pp_id');

        $invoiceid = $this->input->get('invoiceid');
        $hash = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        try {
            $response = $this->piprapay_gateway->fetch_payment($pp_id);

            if ($response['status'] === 'completed') {
                // New payment
                $this->piprapay_gateway->addPayment([
                    'amount'        => $response['metadata']['amount'],
                    'invoiceid'     => $invoice->id,
                    'paymentmethod' => $response['payment_method'],
                    'transactionid' => $response['transaction_id'],
                ]);
                set_alert('success', _l('online_payment_recorded_success'));
            } else {
                set_alert('danger', 'Payment is pending for verification.');
            }
        } catch (\Exception $e) {
            set_alert('danger', $e->getMessage());
        }

        redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
    }

    /**
     * Handle the Piprapay webhook
     *
     * @param  string $key
     *
     * @return mixed
     */
    public function webhook($key = null)
    {

        $response = $this->piprapay_gateway->fetch_payment();

        log_activity('Piprapay payment webhook called.');

        if (!$response) {
            log_activity('Piprapay payment not found via webhook.');

            return;
        }

        if ($response['status'] == 'completed') {
            $this->db->where('id', $response['metadata']['invoice_id']);
            $invoice = $this->db->get(db_prefix() . 'invoices')->row();
            // New payment
            $this->piprapay_gateway->addPayment([
                'amount'        => $response['metadata']['amount'],
                'invoiceid'     => $invoice->id,
                'paymentmethod' => $response['payment_method'],
                'transactionid' => $response['transaction_id'],
            ]);
        } else {
            log_activity('Piprapay payment failed. Status: ' . $response['status']);
        }
    }
}
