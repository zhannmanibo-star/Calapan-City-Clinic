<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Mailer - sends email through the Brevo Email API and records every attempt
 * in the email_logs table. A failed email never breaks the request that asked for it.
 *
 * .env settings:  BREVO_API_KEY=...   MAIL_FROM=you@example.com (a sender verified in Brevo)   MAIL_FROM_NAME=Calapan City Clinic
 * Without BREVO_API_KEY the email is only logged with status "queued".
 */
class Mailer
{
    private $lava;
    private $http;

    public $last_error = null;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->model('Email_log_model');
        $this->lava->call->library('http_client');
        $this->http = $this->lava->http_client;
    }

    private function base_url()
    {
        return rtrim(getenv('BREVO_API_BASE') ?: 'https://api.brevo.com/v3', '/');
    }

    /** Send and log. Returns 'sent', 'failed' or 'queued' */
    public function send($user_id, $appointment_id, $type, $to_email, $subject, $text)
    {
        $status = 'queued';
        if (getenv('BREVO_API_KEY') && getenv('MAIL_FROM')) {
            $status = $this->deliver($to_email, $subject, $text) ? 'sent' : 'failed';
        }

        try {
            $this->lava->Email_log_model->add($user_id, $appointment_id, $type, $subject, $status);
        } catch (Throwable $e) {
            // logging problems must never stop the booking
        }
        return $status;
    }

    /** Send one email through Brevo without logging it. Returns true when Brevo accepted it. */
    public function deliver($to_email, $subject, $text)
    {
        $this->last_error = null;
        $res = $this->http->request('POST', $this->base_url() . '/smtp/email', json_encode([
            'sender'      => ['email' => getenv('MAIL_FROM'), 'name' => getenv('MAIL_FROM_NAME') ?: 'Calapan City Clinic'],
            'to'          => [['email' => $to_email]],
            'subject'     => $subject,
            'textContent' => $text,
        ]), ['accept: application/json', 'content-type: application/json', 'api-key: ' . getenv('BREVO_API_KEY')]);

        if ($res['code'] >= 200 && $res['code'] < 300) return true;
        $this->last_error = $res['error'] ?: 'Brevo said: ' . ($res['data']['message'] ?? 'HTTP ' . $res['code']);
        return false;
    }

    /** For /setup/integrations: is the key valid, and is MAIL_FROM a verified sender? */
    public function check()
    {
        $key  = getenv('BREVO_API_KEY');
        $from = strtolower(trim((string) getenv('MAIL_FROM')));
        $out  = ['configured' => (bool) ($key && $from), 'key_valid' => null, 'sender_verified' => null, 'error' => null];

        if (!$key)  { $out['error'] = 'BREVO_API_KEY is not set in .env'; return $out; }
        if (!$from) { $out['error'] = 'MAIL_FROM is not set in .env'; return $out; }

        $headers = ['accept: application/json', 'api-key: ' . $key];
        $account = $this->http->request('GET', $this->base_url() . '/account', null, $headers);
        $out['key_valid'] = $account['code'] === 200;
        if (!$out['key_valid']) {
            $out['error'] = $account['error'] ?: 'Brevo said: ' . ($account['data']['message'] ?? 'HTTP ' . $account['code']);
            return $out;
        }

        $senders = $this->http->request('GET', $this->base_url() . '/senders', null, $headers);
        if ($senders['code'] === 200) {
            $out['sender_verified'] = false;
            foreach ($senders['data']['senders'] ?? [] as $s) {
                if (strtolower($s['email'] ?? '') === $from && ($s['active'] ?? true)) $out['sender_verified'] = true;
            }
            if (!$out['sender_verified']) $out['error'] = "$from is not an active sender in Brevo. Add it under Senders and click the link Brevo emails you.";
        }
        return $out;
    }
}
