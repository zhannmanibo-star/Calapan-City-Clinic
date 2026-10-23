<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Patient management for the clinic staff (patients register themselves through /auth/register).
 */
class Patients extends Controller
{
    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model(['Patient_model', 'User_model']);
    }

    private function load_or_404($id)
    {
        $patient = $this->Patient_model->find((int) $id);
        if (!$patient) $this->guard->fail('Patient not found.', 404);
        return $patient;
    }

    // GET /api/patients?search=maria   (staff, admin, doctor)
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $this->guard->require_role(['staff', 'admin', 'doctor']);
        $q = $this->guard->query();
        $this->guard->ok(['patients' => $this->Patient_model->list_all(trim($q['search'] ?? ''))]);
    }

    // GET /api/patients/{id}   (staff, admin, doctor)
    public function show($id)
    {
        $this->guard->api()->require_method('GET');
        $this->guard->require_role(['staff', 'admin', 'doctor']);
        $this->guard->ok(['patient' => $this->load_or_404($id)]);
    }

    // POST /api/patients   (staff, admin) - register a walk-in patient
    // { name, email, password, birthdate?, sex?, address?, contact_no? }
    public function store()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['staff', 'admin']);

        $in      = $this->guard->body();
        $name    = $this->guard->name_field($in);
        $email   = $this->guard->email_field($in);
        $pass    = $this->guard->password_field($in);
        $profile = $this->guard->patient_profile($in);
        if ($this->User_model->find_by_email($email)) $this->guard->fail('That email is already registered.', 409);

        $user_id = $this->User_model->register_patient($name, $email, $pass, $profile);
        $this->guard->ok(['message' => 'Patient added.', 'patient' => $this->Patient_model->find_by_user($user_id)], 201);
    }

    // PUT /api/patients/{id}   (staff, admin)   { name?, email?, birthdate?, sex?, address?, contact_no? }
    public function update($id)
    {
        $this->guard->api()->require_method('PUT');
        $this->guard->require_role(['staff', 'admin']);
        $patient = $this->load_or_404($id);

        $in    = $this->guard->body();
        $name  = $this->guard->name_field($in, $patient['name']);
        $email = $this->guard->email_field($in, $patient['email']);
        $other = $this->User_model->find_by_email($email);
        if ($other && (int) $other['id'] !== (int) $patient['user_id']) $this->guard->fail('That email is already registered.', 409);

        $profile = $this->guard->patient_profile(array_merge($patient, array_intersect_key($in, array_flip(['birthdate', 'sex', 'address', 'contact_no']))));

        $this->User_model->update_basic($patient['user_id'], $name, $email);
        $this->Patient_model->update_profile($patient['id'], $profile['birthdate'], $profile['sex'], $profile['address'], $profile['contact_no']);
        $this->guard->ok(['message' => 'Patient updated.', 'patient' => $this->Patient_model->find($patient['id'])]);
    }

    // DELETE /api/patients/{id}   (admin)
    // Safeguard: a patient who already has appointments cannot be deleted.
    public function destroy($id)
    {
        $this->guard->api()->require_method('DELETE');
        $this->guard->require_role(['admin']);
        $patient = $this->load_or_404($id);

        if ($this->Patient_model->has_appointments($patient['id'])) {
            $this->guard->fail('This patient has appointments on record and cannot be deleted.', 409);
        }
        $this->Patient_model->delete_completely($patient['id']);
        $this->guard->ok(['message' => 'Patient deleted.']);
    }
}
