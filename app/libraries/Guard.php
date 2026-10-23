<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Guard - small helper used by every API controller.
 *  - turns on CORS so the React frontend may call the API
 *  - reads the JSON body
 *  - checks the login token (JWT) and the user's role
 *  - sends JSON responses
 */
class Guard
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        handle_cors();
        $this->_lava->call->library('api');
    }

    /** The Api library (login tokens, rate limit, ...) */
    public function api()
    {
        return $this->_lava->api;
    }

    /** JSON request body as an array */
    public function body()
    {
        return $this->_lava->api->body();
    }

    /** Query string (?date=...) as an array */
    public function query()
    {
        return $this->_lava->api->get_query_params();
    }

    public function ok($data, $code = 200)
    {
        $this->_lava->api->respond($data, $code);
    }

    public function fail($message, $code = 400)
    {
        $this->_lava->api->respond_error($message, $code);
    }

    /** Requires a valid login. Returns ['sub' => user id, 'role' => role, ...] */
    public function user()
    {
        return $this->_lava->api->require_jwt();
    }

    /** Requires a valid login AND one of the given roles, else 403. */
    public function require_role(array $roles)
    {
        $payload = $this->user();
        if (!in_array($payload['role'], $roles, true)) {
            $this->fail('Access denied', 403);
        }
        return $payload;
    }

    /** A person's name from the request body (2 to 100 characters) */
    public function name_field(array $in, $current = null, $key = 'name')
    {
        $name = trim($in[$key] ?? ($current ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $this->fail('Name must be 2 to 100 characters.', 422);
        return $name;
    }

    /** A valid email address from the request body, in lowercase */
    public function email_field(array $in, $current = null, $key = 'email')
    {
        $email = strtolower(trim($in[$key] ?? ($current ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $this->fail('Please enter a valid email address.', 422);
        return $email;
    }

    /** A password of at least 8 characters */
    public function password_field(array $in, $key = 'password')
    {
        $password = (string) ($in[$key] ?? '');
        if (mb_strlen($password) < 8) $this->fail('Password must be at least 8 characters.', 422);
        return $password;
    }

    /** Optional contact number; returns null when empty */
    public function phone_field(array $in, $current = null, $key = 'contact_no')
    {
        $phone = trim($in[$key] ?? ($current ?? ''));
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $this->fail('Contact number is not valid.', 422);
        return $phone ?: null;
    }

    /** The optional patient profile fields: birthdate, sex, address, contact_no */
    public function patient_profile(array $in)
    {
        $birth = trim($in['birthdate'] ?? '');
        $sex   = trim($in['sex'] ?? '');
        $addr  = trim($in['address'] ?? '');

        if ($birth !== '' && (!$this->valid_date($birth) || $birth > date('Y-m-d'))) $this->fail('Birthdate must look like 2000-05-17 and cannot be in the future.', 422);
        if ($sex !== '' && !in_array($sex, ['Male', 'Female', 'Other'], true)) $this->fail('Sex must be Male, Female or Other.', 422);
        if (mb_strlen($addr) > 255) $this->fail('Address is too long (255 characters max).', 422);

        return ['birthdate' => $birth ?: null, 'sex' => $sex ?: null, 'address' => $addr ?: null, 'contact_no' => $this->phone_field($in)];
    }

    /** Strict date check (YYYY-MM-DD) */
    public function valid_date($value)
    {
        $d = DateTime::createFromFormat('Y-m-d', (string) $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    /** Strict time check (HH:MM or HH:MM:SS); returns HH:MM:SS or false */
    public function norm_time($value)
    {
        $value = (string) $value;
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) return $value . ':00';
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $value)) return $value;
        return false;
    }

    /**
     * Estimated time a patient will be seen: the clinic hours are shared equally
     * between max_slots patients, so queue number 1 starts at the opening time.
     */
    public function estimate($start, $end, $max_slots, $queue_number)
    {
        $from = strtotime('1970-01-01 ' . $start);
        $to   = strtotime('1970-01-01 ' . $end);
        $step = intdiv(max(0, $to - $from), max(1, (int) $max_slots));
        return date('H:i', $from + (max(1, (int) $queue_number) - 1) * $step);
    }

    /** Adds estimated_time to an appointment/ticket row before sending it to the frontend */
    public function with_estimate($row)
    {
        if ($row && isset($row['queue_number'], $row['start_time'], $row['end_time'], $row['max_slots'])) {
            $row['estimated_time'] = $this->estimate($row['start_time'], $row['end_time'], $row['max_slots'], $row['queue_number']);
        }
        return $row;
    }
}
