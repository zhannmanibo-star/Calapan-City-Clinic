<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Mailer - sends email through the Brevo Email API and records every attempt
 * in the email_logs table. A failed email never breaks the request that asked for it.
 *
 * .env settings:  BREVO_API_KEY=...   MAIL_FROM=you@example.com   MAIL_FROM_NAME=Calapan City Clinic
 * Without BREVO_API_KEY the email is only logged with status "queued".
 */
class Mailer
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->model('Email_log_model');
    }

    /** Returns 'sent', 'failed' or 'queued' */
    public function send($user_id, $appointment_id, $type, $to_email, $subject, $text)
    {
        $status = 'queued';
        $key  = getenv('BREVO_API_KEY');
        $from = getenv('MAIL_FROM');

        if ($key && $from) {
            $status = $this->post_to_brevo($key, $from, getenv('MAIL_FROM_NAME') ?: 'Calapan City Clinic', $to_email, $subject, $text)
                ? 'sent' : 'failed';
        }

        try {
            $this->_lava->Email_log_model->add($user_id, $appointment_id, $type, $subject, $status);
        } catch (Throwable $e) {
            // logging problems must never stop the booking
        }
        return $status;
    }

    private function post_to_brevo($key, $from, $from_name, $to, $subject, $text)
    {
        if (!function_exists('curl_init')) return false;
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
            CURLOPT_POSTFIELDS     => json_encode([
                'sender'      => ['email' => $from, 'name' => $from_name],
                'to'          => [['email' => $to]],
                'subject'     => $subject,
                'textContent' => $text,
            ]),
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}
