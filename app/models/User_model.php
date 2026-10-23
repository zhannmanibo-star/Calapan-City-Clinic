<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * users table (+ the patient / doctor profile ids that belong to a user).
 * Uses the LavaLust Query Builder.
 */
class User_model extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';
    protected $fillable = ['google_id', 'name', 'email', 'password', 'role'];
    protected $guarded = ['id'];

    // Base query for sending a user to the frontend (never includes the password)
    private function public_query()
    {
        return $this->db->table('users')
            ->select('users.id, users.name, users.email, users.role, users.created_at, patients.id AS patient_id, doctors.id AS doctor_id')
            ->left_join('patients', 'patients.user_id = users.id')
            ->left_join('doctors', 'doctors.user_id = users.id');
    }

    public function find_by_email($email)
    {
        return $this->db->table('users')->where('email', $email)->row_array() ?: null;
    }

    public function find_by_google_id($google_id)
    {
        return $this->db->table('users')->where('google_id', $google_id)->row_array() ?: null;
    }

    public function find_public($id)
    {
        return $this->public_query()->where('users.id', $id)->row_array() ?: null;
    }

    public function all_public()
    {
        return $this->public_query()->order_by('users.id')->result_array();
    }

    /** Insert into users. $password may be null (Google-only accounts). */
    public function create($name, $email, $password, $role, $google_id = null)
    {
        $this->db->table('users')->insert([
            'google_id' => $google_id,
            'name'      => $name,
            'email'     => $email,
            'password'  => $password === null ? null : password_hash($password, PASSWORD_DEFAULT),
            'role'      => $role,
        ]);
        return (int) $this->db->last_id();
    }

    /** A new patient = one users row + one patients row (saved together) */
    public function register_patient($name, $email, $password, array $profile = [], $google_id = null)
    {
        $this->db->transaction();
        try {
            $id = $this->create($name, $email, $password, 'patient', $google_id);
            $this->db->table('patients')->insert([
                'user_id'    => $id,
                'birthdate'  => $profile['birthdate'] ?? null,
                'sex'        => $profile['sex'] ?? null,
                'address'    => $profile['address'] ?? null,
                'contact_no' => $profile['contact_no'] ?? null,
            ]);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function update_basic($id, $name, $email)
    {
        $this->db->table('users')->where('id', $id)->update(['name' => $name, 'email' => $email]);
    }

    public function link_google($id, $google_id)
    {
        $this->db->table('users')->where('id', $id)->update(['google_id' => $google_id]);
    }

    /** Delete an account (and its login tokens) */
    public function remove($id)
    {
        $this->db->table('refresh_tokens')->where('user_id', $id)->delete();
        $this->db->table('users')->where('id', $id)->delete();
    }
}
