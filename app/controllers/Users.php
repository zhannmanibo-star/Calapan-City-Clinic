<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Staff / Admin panel -> User Accounts (admin only).
 * Patients register themselves; doctors are created in Doctors::store.
 */
class Users extends Controller
{
    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model('User_model');
    }

    // GET /api/users
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $this->guard->require_role(['admin']);
        $this->guard->ok(['users' => $this->User_model->all_public()]);
    }

    // POST /api/users   { name, email, password, role: staff|admin }
    public function store()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['admin']);

        $in    = $this->guard->body();
        $name  = $this->guard->name_field($in);
        $email = $this->guard->email_field($in);
        $pass  = $this->guard->password_field($in);
        $role  = (string) ($in['role'] ?? '');

        if (!in_array($role, ['staff', 'admin'], true)) $this->guard->fail('Role must be staff or admin.', 422);
        if ($this->User_model->find_by_email($email))   $this->guard->fail('That email is already registered.', 409);

        $id = $this->User_model->create($name, $email, $pass, $role);
        $this->guard->ok(['message' => 'Account created.', 'user' => $this->User_model->find_public($id)], 201);
    }

    // PUT /api/users/{id}   { name?, email? }   (staff and admin accounts only)
    public function update($id)
    {
        $this->guard->api()->require_method('PUT');
        $this->guard->require_role(['admin']);

        $user = $this->User_model->find_public((int) $id);
        if (!$user || !in_array($user['role'], ['staff', 'admin'], true)) $this->guard->fail('Staff or admin account not found.', 404);

        $in    = $this->guard->body();
        $name  = $this->guard->name_field($in, $user['name']);
        $email = $this->guard->email_field($in, $user['email']);

        $other = $this->User_model->find_by_email($email);
        if ($other && (int) $other['id'] !== (int) $user['id']) $this->guard->fail('That email is already registered.', 409);

        $this->User_model->update_basic($user['id'], $name, $email);
        $this->guard->ok(['message' => 'Account updated.', 'user' => $this->User_model->find_public($user['id'])]);
    }

    // DELETE /api/users/{id}   (staff and admin accounts only; not your own)
    public function destroy($id)
    {
        $this->guard->api()->require_method('DELETE');
        $payload = $this->guard->require_role(['admin']);

        $user = $this->User_model->find_public((int) $id);
        if (!$user || !in_array($user['role'], ['staff', 'admin'], true)) $this->guard->fail('Staff or admin account not found.', 404);
        if ((int) $user['id'] === (int) $payload['sub']) $this->guard->fail('You cannot delete your own account.', 409);

        try {
            $this->User_model->remove($user['id']);
        } catch (Throwable $e) {
            $this->guard->fail('This account is linked to other records and cannot be deleted.', 409);
        }
        $this->guard->ok(['message' => 'Account deleted.']);
    }
}
