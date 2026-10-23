<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Doctor profiles (a doctor = a users row with role "doctor" + a doctors row).
 */
class Doctors extends Controller
{
    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model(['Doctor_model', 'User_model']);
    }

    // Reads and checks the fields shared by store() and update()
    private function read_fields(array $in, $current = null)
    {
        $name  = trim($in['name'] ?? ($current['name'] ?? ''));
        $email = strtolower(trim($in['email'] ?? ($current['email'] ?? '')));
        $spec  = trim($in['specialization'] ?? ($current['specialization'] ?? ''));
        $phone = trim($in['contact_no'] ?? ($current['contact_no'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $this->guard->fail('Name must be 2 to 100 characters.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $this->guard->fail('Please enter a valid email address.', 422);
        if ($spec === '' || mb_strlen($spec) > 100) $this->guard->fail('Specialization is required (100 characters max).', 422);
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $this->guard->fail('Contact number is not valid.', 422);

        return [$name, $email, $spec, $phone ?: null];
    }

    // GET /api/doctors
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $this->guard->user();
        $this->guard->ok(['doctors' => $this->Doctor_model->all_doctors()]);
    }

    // GET /api/doctors/{id}
    public function show($id)
    {
        $this->guard->api()->require_method('GET');
        $this->guard->user();
        $doctor = $this->Doctor_model->find((int) $id);
        if (!$doctor) $this->guard->fail('Doctor not found.', 404);
        $this->guard->ok(['doctor' => $doctor]);
    }

    // POST /api/doctors   (staff/admin)
    // { name, email, password, specialization, contact_no? }  -> also creates the doctor's login
    public function store()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['staff', 'admin']);

        $in = $this->guard->body();
        [$name, $email, $spec, $phone] = $this->read_fields($in);
        $pass = (string) ($in['password'] ?? '');
        if (mb_strlen($pass) < 8) $this->guard->fail('Password must be at least 8 characters.', 422);
        if ($this->User_model->find_by_email($email)) $this->guard->fail('That email is already registered.', 409);

        $id = $this->Doctor_model->create_with_user($name, $email, $pass, $spec, $phone);
        $this->guard->ok(['message' => 'Doctor added.', 'doctor' => $this->Doctor_model->find($id)], 201);
    }

    // PUT /api/doctors/{id}   (staff/admin)
    public function update($id)
    {
        $this->guard->api()->require_method('PUT');
        $this->guard->require_role(['staff', 'admin']);

        $doctor = $this->Doctor_model->find((int) $id);
        if (!$doctor) $this->guard->fail('Doctor not found.', 404);

        [$name, $email, $spec, $phone] = $this->read_fields($this->guard->body(), $doctor);
        $other = $this->User_model->find_by_email($email);
        if ($other && (int) $other['id'] !== (int) $doctor['user_id']) $this->guard->fail('That email is already registered.', 409);

        $this->Doctor_model->update_profile($doctor['id'], $name, $email, $spec, $phone);
        $this->guard->ok(['message' => 'Doctor updated.', 'doctor' => $this->Doctor_model->find($doctor['id'])]);
    }

    // DELETE /api/doctors/{id}   (staff/admin)
    // Safeguard: a doctor who already has appointments or records cannot be deleted.
    public function destroy($id)
    {
        $this->guard->api()->require_method('DELETE');
        $this->guard->require_role(['staff', 'admin']);

        $doctor = $this->Doctor_model->find((int) $id);
        if (!$doctor) $this->guard->fail('Doctor not found.', 404);

        if ($this->Doctor_model->has_history($doctor['id'])) {
            $this->guard->fail('This doctor has appointments or records and cannot be deleted.', 409);
        }

        $this->Doctor_model->delete_completely($doctor['id']);
        $this->guard->ok(['message' => 'Doctor deleted.']);
    }
}
