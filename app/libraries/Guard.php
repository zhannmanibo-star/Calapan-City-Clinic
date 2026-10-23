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
