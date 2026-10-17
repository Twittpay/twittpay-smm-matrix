<?php

namespace App\Services\Gateway\twittpay;

use Exception;
use Facades\App\Services\BasicService;

/**
 * TwittPay - SMM Matrix gateway
 * ---------------------------------------------------------------------------
 * prepareData()  creates the payment and hands the checkout URL to the panel
 * ipn()          answers both the returning user and the gateway's webhook
 *
 * The deposit is only credited after the transaction has been verified against
 * the API, and only once - a deposit that is already paid is left alone.
 *
 * @version 1.0.0
 */
class Payment
{
    public static function prepareData($deposit, $gateway)
    {
        $params = $gateway->parameters;

        throw_if(
            empty($params->api_key ?? ""),
            "This payment method is not fully configured yet."
        );

        $requestData = [
            ...self::customerFields($deposit),
            'amount'      => number_format(round($deposit->payable_amount, 2), 2, '.', ''),
            'success_url' => route('ipn', [$gateway->code, $deposit->trx_id]),
            'cancel_url'  => route('failed'),
            'webhook_url' => route('ipn', [$gateway->code, $deposit->trx_id]),
            'meta_data'   => [
                'trx_id' => (string) $deposit->trx_id,
                'source' => 'smm-matrix',
            ],
        ];

        try {
            $redirect_url = self::initPayment($requestData, $params);

            return json_encode([
                'redirect'     => true,
                'redirect_url' => $redirect_url,
            ]);
        } catch (Exception $e) {
            return json_encode([
                'error'   => true,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The gateway's webhook posts here, and the returning user arrives here too.
     * Either way the transaction is verified against the API before anything is
     * credited.
     */
    public static function ipn($request, $gateway, $deposit = null, $trx = null, $type = null)
    {
        $params = $gateway->parameters;

        if (!$deposit) {
            return [
                'status'   => 'error',
                'msg'      => 'This deposit could not be found.',
                'redirect' => route('failed'),
            ];
        }

        // Already credited - the webhook fires again when a pending payment is
        // decided, so this has to stay safe to call twice.
        if ($deposit->status == 1) {
            return [
                'status'   => 'success',
                'msg'      => 'Transaction was successful.',
                'redirect' => route('success'),
            ];
        }

        $transactionId = self::transactionIdFrom($request);

        if ($transactionId === '') {
            return [
                'status'   => 'error',
                'msg'      => 'No transaction was received.',
                'redirect' => route('failed'),
            ];
        }

        try {
            $response = self::verifyPayment($transactionId, $params);
        } catch (Exception $e) {
            return [
                'status'   => 'error',
                'msg'      => 'The payment could not be checked right now.',
                'redirect' => route('failed'),
            ];
        }

        $status = self::readStatus($response);

        if ($status === '') {
            return [
                'status'   => 'error',
                'msg'      => 'The gateway does not know this transaction.',
                'redirect' => route('failed'),
            ];
        }

        $meta = self::metadata($response);

        // The trx id is written into metadata when the payment is created, so a
        // payment cannot be pointed at somebody else's deposit.
        if (!empty($meta['trx_id']) && !hash_equals((string) $deposit->trx_id, (string) $meta['trx_id'])) {
            return [
                'status'   => 'error',
                'msg'      => 'This payment belongs to another deposit.',
                'redirect' => route('failed'),
            ];
        }

        if ($status === 'PENDING') {
            // Sent, not approved by the merchant yet. The gateway calls again with
            // the answer, so nothing is credited now and the deposit stays open.
            return [
                'status'   => 'error',
                'msg'      => 'Your payment is being checked. Your balance will be updated once it clears.',
                'redirect' => route('user.add.fund'),
            ];
        }

        if ($status !== 'COMPLETED') {
            return [
                'status'   => 'error',
                'msg'      => 'The payment was not completed.',
                'redirect' => route('failed'),
            ];
        }

        // Short of what was asked for - do not credit it.
        $paid = isset($response['amount']) ? (float) $response['amount'] : 0;

        if ($paid + 0.01 < (float) $deposit->payable_amount) {
            return [
                'status'   => 'error',
                'msg'      => 'The amount paid is less than the amount due.',
                'redirect' => route('failed'),
            ];
        }

        BasicService::preparePaymentUpgradation($deposit);

        return [
            'status'   => 'success',
            'msg'      => 'Transaction was successful.',
            'redirect' => route('success'),
        ];
    }

    public static function initPayment($requestData, $params)
    {
        $result = self::apiCall('/api/payment/create', $requestData, $params);

        if (!empty($result['status']) && !empty($result['payment_url'])) {
            return $result['payment_url'];
        }

        // The API message is not passed on - an error string can carry the key back
        // out to the user.
        throw new Exception('The payment could not be started. Please try again.');
    }

    public static function verifyPayment($transactionId, $params)
    {
        return self::apiCall('/api/payment/verify', ['transaction_id' => $transactionId], $params);
    }

    /** The transaction id, from the URL, a form body, or a JSON body. */
    protected static function transactionIdFrom($request)
    {
        foreach (['transactionId', 'transaction_id'] as $key) {
            $value = $request ? $request->input($key) : null;

            if (!empty($value)) {
                return trim((string) $value);
            }
        }

        $raw = $request ? $request->getContent() : file_get_contents('php://input');

        if (!empty($raw)) {
            $body = json_decode($raw, true);

            if (is_array($body)) {
                foreach (['transactionId', 'transaction_id'] as $key) {
                    if (!empty($body[$key])) {
                        return trim((string) $body[$key]);
                    }
                }
            }
        }

        return '';
    }

    /**
     * The verify status: PENDING, COMPLETED or ERROR when the transaction is real,
     * and an empty string when it is not - a miss answers a number, not text.
     */
    protected static function readStatus($verified)
    {
        if (!is_array($verified) || !isset($verified['status']) || !is_string($verified['status'])) {
            return '';
        }

        return strtoupper(trim($verified['status']));
    }

    /** metadata comes back from verify as a JSON string. */
    protected static function metadata($verified)
    {
        if (!is_array($verified) || (!isset($verified['meta_data']) && !isset($verified['metadata']))) {
            return [];
        }

        $meta = $verified['meta_data'] ?? $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Scheme and host of the configured endpoint. Pasting the whole endpoint or a
     * trailing /api still works.
     */
    protected static function baseUrl($params)
    {
        return 'https://checkout.twittpay.com';
    }

    /** One POST to the API. JSON in, array out. */
    /**
     * Real customer details only. TwittPay never accepts made-up values: a field the
     * user does not have is left out and TwittPay asks the customer for it.
     */
    public static function customerFields($deposit): array
    {
        $u = $deposit->user ?? null;
        $out = [];
        $name = trim((string)($u->fullname ?? ''));
        if ($name === '') {
            $name = trim(trim((string)($u->firstname ?? '')) . ' ' . trim((string)($u->lastname ?? '')));
        }
        if ($name === '') {
            $name = trim((string)($u->username ?? ''));
        }
        if ($name !== '') {
            $out['cus_name'] = $name;
        }
        $email = trim((string)($u->email ?? ''));
        if ($email !== '') {
            $out['cus_email'] = $email;
        }
        $digits = preg_replace('/\D+/', '', (string)($u->phone_code ?? '') . (string)($u->phone ?? ''));
        if (strpos($digits, '880') === 0) {
            $digits = '0' . ltrim(substr($digits, 3), '0');
        } elseif (preg_match('/^1\d{9}$/', $digits)) {
            $digits = '0' . $digits;
        }
        if (preg_match('/^01\d{9}$/', $digits)) {
            $out['cus_phone'] = $digits;
        }
        $addr = trim(trim((string)($u->address_one ?? '')) . ' ' . trim((string)($u->address_two ?? '')));
        if ($addr !== '') {
            $out['cus_address'] = $addr;
        }
        return $out;
    }

    protected static function apiCall($endpoint, $payload, $params)
    {
        // metadata has to arrive as a JSON object; a PHP list would encode as an
        // array and be rejected.
        if (isset($payload['meta_data'])) {
            $payload['meta_data'] = (object) $payload['meta_data'];
        }

        $options = [
            CURLOPT_URL            => self::baseUrl($params) . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "API-KEY: " . trim((string) ($params->api_key ?? '')),
                "Accept: application/json",
                "Content-Type: application/json",
            ],
        ];

        // A short outage (DNS hiccup, refused connection) is retried up to 3 times.
        // Any real answer from the gateway is returned at once and never retried.
        $retryable = [6, 7, 35];
        $response  = false;
        $error     = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $curl = curl_init();
            curl_setopt_array($curl, $options);
            $response = curl_exec($curl);
            $error    = curl_error($curl);
            $errno    = curl_errno($curl);
            curl_close($curl);
            if (!$error || !in_array($errno, $retryable, true) || $attempt === 3) {
                break;
            }
            sleep($attempt);
        }

        if ($error) {
            throw new Exception("Connection error: " . $error);
        }

        $result = json_decode($response, true);

        return is_array($result) ? $result : [];
    }
}
