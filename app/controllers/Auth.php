<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Registration, login (email or Google), token refresh, logout, profile.
 */
class Auth extends Controller
{
    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model(['User_model', 'Patient_model']);
    }

    private function scopes($role)
    {
        if ($role === 'admin') return ['read', 'write', 'delete'];
        if ($role === 'patient') return ['read'];
        return ['read', 'write'];
    }

    private function tokens_for($id, $role)
    {
        return $this->guard->api()->issue_tokens(['id' => $id, 'role' => $role, 'scopes' => $this->scopes($role)]);
    }

    private function ip()
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    // Reads and checks the optional patient profile fields
    private function profile_input(array $in)
    {
        $birth = trim($in['birthdate'] ?? '');
        $sex   = trim($in['sex'] ?? '');
        $addr  = trim($in['address'] ?? '');
        $phone = trim($in['contact_no'] ?? '');

        if ($birth !== '' && (!$this->guard->valid_date($birth) || $birth > date('Y-m-d'))) $this->guard->fail('Birthdate must look like 2000-05-17 and cannot be in the future.', 422);
        if ($sex !== '' && !in_array($sex, ['Male', 'Female', 'Other'], true)) $this->guard->fail('Sex must be Male, Female or Other.', 422);
        if (mb_strlen($addr) > 255) $this->guard->fail('Address is too long (255 characters max).', 422);
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $this->guard->fail('Contact number is not valid.', 422);

        return ['birthdate' => $birth ?: null, 'sex' => $sex ?: null, 'address' => $addr ?: null, 'contact_no' => $phone ?: null];
    }

    // POST /api/auth/register  -> new patient account
    // { name, email, password, birthdate?, sex?, address?, contact_no? }
    public function register()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->api()->rate_limit('register_' . $this->ip(), 10, 60);

        $in    = $this->guard->body();
        $name  = trim($in['name'] ?? '');
        $email = strtolower(trim($in['email'] ?? ''));
        $pass  = (string) ($in['password'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $this->guard->fail('Name must be 2 to 100 characters.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $this->guard->fail('Please enter a valid email address.', 422);
        if (mb_strlen($pass) < 8) $this->guard->fail('Password must be at least 8 characters.', 422);
        $profile = $this->profile_input($in);

        if ($this->User_model->find_by_email($email)) $this->guard->fail('That email is already registered.', 409);

        $id = $this->User_model->register_patient($name, $email, $pass, $profile);
        $this->guard->ok([
            'message' => 'Account created.',
            'user'    => $this->User_model->find_public($id),
            'tokens'  => $this->tokens_for($id, 'patient'),
        ], 201);
    }

    // POST /api/auth/login  { email, password }
    public function login()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->api()->rate_limit('login_' . $this->ip(), 10, 60);

        $in    = $this->guard->body();
        $email = strtolower(trim($in['email'] ?? ''));
        $pass  = (string) ($in['password'] ?? '');

        $user = $this->User_model->find_by_email($email);
        if (!$user || empty($user['password']) || !password_verify($pass, $user['password'])) {
            $this->guard->fail('Invalid email or password.', 401);
        }

        $this->guard->ok([
            'message' => 'Login successful.',
            'user'    => $this->User_model->find_public($user['id']),
            'tokens'  => $this->tokens_for((int) $user['id'], $user['role']),
        ]);
    }

    // POST /api/auth/google  { credential }   (the ID token from "Sign in with Google")
    // Needs GOOGLE_CLIENT_ID in .env
    public function google()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->api()->rate_limit('google_' . $this->ip(), 20, 60);

        $client_id = getenv('GOOGLE_CLIENT_ID');
        if (!$client_id) $this->guard->fail('Google login is not configured on the server.', 503);

        $in  = $this->guard->body();
        $g   = $this->verify_google_token((string) ($in['credential'] ?? ''), $client_id);
        if (!$g) $this->guard->fail('Google sign-in failed. Please try again.', 401);

        $user = $this->User_model->find_by_google_id($g['sub']);
        if (!$user) {
            $user = $this->User_model->find_by_email($g['email']);
            if ($user) {
                $this->User_model->link_google($user['id'], $g['sub']);   // same email: link the accounts
            } else {
                $id   = $this->User_model->register_patient($g['name'], $g['email'], null, [], $g['sub']);
                $user = $this->User_model->find_by_email($g['email']);
            }
        }

        $this->guard->ok([
            'message' => 'Login successful.',
            'user'    => $this->User_model->find_public($user['id']),
            'tokens'  => $this->tokens_for((int) $user['id'], $user['role']),
        ]);
    }

    // Asks Google whether the ID token is real, and checks it was made for our app
    private function verify_google_token($credential, $client_id)
    {
        if ($credential === '' || !function_exists('curl_init')) return null;
        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$raw) return null;

        $d = json_decode($raw, true);
        if (!$d || ($d['aud'] ?? '') !== $client_id) return null;
        if (!in_array($d['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
        if (($d['email_verified'] ?? '') !== 'true' && ($d['email_verified'] ?? false) !== true) return null;
        if (empty($d['sub']) || empty($d['email'])) return null;

        return ['sub' => substr($d['sub'], 0, 50), 'email' => strtolower($d['email']), 'name' => mb_substr($d['name'] ?? $d['email'], 0, 100)];
    }

    // POST /api/auth/refresh  { refresh_token }
    public function refresh()
    {
        $this->guard->api()->require_method('POST');
        $in = $this->guard->body();
        $this->guard->api()->refresh_access_token((string) ($in['refresh_token'] ?? ''));
    }

    // POST /api/auth/logout  { refresh_token }
    public function logout()
    {
        $this->guard->api()->require_method('POST');
        $in = $this->guard->body();
        if (!empty($in['refresh_token'])) $this->guard->api()->revoke_refresh_token((string) $in['refresh_token']);
        $this->guard->ok(['message' => 'Logged out.']);
    }

    // GET /api/auth/me
    public function me()
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->user();
        $user = $this->User_model->find_public($payload['sub']);
        $profile = $user && $user['patient_id'] ? $this->Patient_model->find($user['patient_id']) : null;
        $this->guard->ok(['user' => $user, 'patient_profile' => $profile]);
    }

    // PUT /api/auth/profile  (patients)  { name?, birthdate?, sex?, address?, contact_no? }
    public function update_profile()
    {
        $this->guard->api()->require_method('PUT');
        $payload = $this->guard->require_role(['patient']);

        $patient = $this->Patient_model->find_by_user($payload['sub']);
        $in = $this->guard->body();
        $name = trim($in['name'] ?? $patient['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $this->guard->fail('Name must be 2 to 100 characters.', 422);

        $merged = array_merge([
            'birthdate' => $patient['birthdate'], 'sex' => $patient['sex'],
            'address' => $patient['address'], 'contact_no' => $patient['contact_no'],
        ], array_intersect_key($in, array_flip(['birthdate', 'sex', 'address', 'contact_no'])));
        $p = $this->profile_input($merged);

        $this->User_model->update_basic($payload['sub'], $name, $patient['email']);
        $this->Patient_model->update_profile($patient['id'], $p['birthdate'], $p['sex'], $p['address'], $p['contact_no']);
        $this->guard->ok(['message' => 'Profile updated.', 'patient_profile' => $this->Patient_model->find($patient['id'])]);
    }
}
