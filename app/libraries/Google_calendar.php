<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Google_calendar - talks to the Google Calendar API with a service account.
 *
 * .env settings:
 *   GOOGLE_SERVICE_ACCOUNT_JSON  the key file's contents, OR a path to the file, OR its base64 text
 *   GOOGLE_CALENDAR_ID           the clinic calendar (shared with the service account, "Make changes to events")
 *
 * Every method returns null / false on failure instead of throwing, and keeps the reason in
 * $last_error, so a calendar problem can never break a booking.
 */
class Google_calendar
{
    private $account = null;
    private $calendar_id = '';
    private $token = null;
    private $token_expires = 0;
    private $http;

    public $last_error = null;
    public $last_code  = 0;

    public function __construct()
    {
        lava_instance()->call->library('http_client');
        $this->http        = lava_instance()->http_client;
        $this->calendar_id = trim((string) getenv('GOOGLE_CALENDAR_ID'));
        $this->account     = $this->read_account((string) getenv('GOOGLE_SERVICE_ACCOUNT_JSON'));
    }

    public function enabled()
    {
        return $this->calendar_id !== '' && $this->account !== null;
    }

    // Accepts the JSON itself, a file path, or base64 of the JSON (handy for Render's env vars)
    private function read_account($value)
    {
        $value = trim($value);
        if ($value === '') return null;

        if ($value[0] !== '{') {
            $path = preg_match('#^([A-Za-z]:)?[\\\\/]#', $value) ? $value : ROOT_DIR . $value;
            if (is_file($path)) {
                $value = (string) file_get_contents($path);
            } else {
                $decoded = base64_decode($value, true);
                if ($decoded !== false) $value = $decoded;
            }
        }

        $json = json_decode($value, true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) return null;
        $json['token_uri'] = $json['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        return $json;
    }

    private function base64url($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // Signs a short-lived token with the service account's private key and trades it for an access token
    private function access_token()
    {
        if ($this->token && time() < $this->token_expires - 60) return $this->token;

        $now    = time();
        $claims = ['iss' => $this->account['client_email'], 'scope' => 'https://www.googleapis.com/auth/calendar',
                   'aud' => $this->account['token_uri'], 'iat' => $now, 'exp' => $now + 3600];
        $unsigned = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $this->base64url(json_encode($claims));

        $signature = '';
        if (!@openssl_sign($unsigned, $signature, $this->account['private_key'], OPENSSL_ALGO_SHA256)) {
            $this->last_error = 'Could not sign with the service account key. Check GOOGLE_SERVICE_ACCOUNT_JSON.';
            return null;
        }

        $res = $this->http->request('POST', $this->account['token_uri'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . $this->base64url($signature),
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        if ($res['code'] !== 200 || empty($res['data']['access_token'])) {
            $this->last_error = $res['error'] ?: 'Google rejected the service account: ' . ($res['data']['error_description'] ?? $res['data']['error'] ?? 'HTTP ' . $res['code']);
            return null;
        }
        $this->token         = $res['data']['access_token'];
        $this->token_expires = $now + (int) ($res['data']['expires_in'] ?? 3600);
        return $this->token;
    }

    // Calls the Calendar API for the clinic calendar. Returns ['code', 'data'] or null.
    private function call($method, $path, $body = null)
    {
        $this->last_error = null;
        $this->last_code  = 0;
        if (!$this->enabled()) { $this->last_error = 'Google Calendar is not configured.'; return null; }

        $token = $this->access_token();
        if (!$token) return null;

        $base = rtrim(getenv('GOOGLE_CALENDAR_API_BASE') ?: 'https://www.googleapis.com/calendar/v3', '/');
        $res  = $this->http->request($method, $base . '/calendars/' . rawurlencode($this->calendar_id) . $path,
            $body === null ? null : json_encode($body),
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);

        $this->last_code = $res['code'];
        if ($res['error']) { $this->last_error = $res['error']; return null; }
        if ($res['code'] >= 400) {
            $this->last_error = 'Google Calendar said: ' . ($res['data']['error']['message'] ?? 'HTTP ' . $res['code']);
        }
        return $res;
    }

    // Event body: date is Y-m-d, times are H:i:s, in Philippine time
    private function event_body($summary, $description, $date, $start, $end)
    {
        return [
            'summary'     => $summary,
            'description' => $description,
            'start'       => ['dateTime' => "{$date}T{$start}", 'timeZone' => 'Asia/Manila'],
            'end'         => ['dateTime' => "{$date}T{$end}",   'timeZone' => 'Asia/Manila'],
        ];
    }

    /** Returns the new event id, or null */
    public function create_event($summary, $description, $date, $start, $end)
    {
        $res = $this->call('POST', '/events', $this->event_body($summary, $description, $date, $start, $end));
        return ($res && $res['code'] < 300 && !empty($res['data']['id'])) ? $res['data']['id'] : null;
    }

    /** Returns true when updated. Check $last_code for 404/410 (event was deleted in Google Calendar). */
    public function update_event($event_id, $summary, $description, $date, $start, $end)
    {
        $res = $this->call('PATCH', '/events/' . rawurlencode($event_id), $this->event_body($summary, $description, $date, $start, $end));
        return $res && $res['code'] < 300;
    }

    /** Returns true when the event is gone (already deleted counts as gone) */
    public function delete_event($event_id)
    {
        $res = $this->call('DELETE', '/events/' . rawurlencode($event_id));
        if (!$res) return false;
        if (in_array($res['code'], [404, 410], true)) { $this->last_error = null; return true; }
        return $res['code'] < 300;
    }

    /** For /setup/integrations: get a token, create a test event, delete it */
    public function self_test()
    {
        $result = ['configured' => $this->enabled(), 'token' => false, 'event' => false, 'deleted' => false, 'error' => null];
        if (!$result['configured']) {
            $result['error'] = 'Set GOOGLE_SERVICE_ACCOUNT_JSON and GOOGLE_CALENDAR_ID in .env';
            return $result;
        }
        $result['token'] = (bool) $this->access_token();
        if ($result['token']) {
            $date = date('Y-m-d', strtotime('+1 day'));
            $id = $this->create_event('Clinic integration test (safe to delete)', 'Created by /setup/integrations', $date, '09:00:00', '09:15:00');
            $result['event'] = $id !== null;
            if ($id) $result['deleted'] = $this->delete_event($id);
        }
        $result['error'] = $this->last_error;
        return $result;
    }
}
