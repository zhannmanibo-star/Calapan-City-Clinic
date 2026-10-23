<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * patients table (name and email live in the linked users row).
 */
class Patient_model extends Model
{
    protected $table = 'patients';
    protected $primary_key = 'id';
    protected $fillable = ['user_id', 'birthdate', 'sex', 'address', 'contact_no'];
    protected $guarded = ['id'];

    private function base_query()
    {
        return $this->db->table('patients')
            ->select('patients.id, patients.user_id, users.name, users.email, patients.birthdate, patients.sex, patients.address, patients.contact_no')
            ->inner_join('users', 'users.id = patients.user_id');
    }

    public function find($id)
    {
        return $this->base_query()->where('patients.id', $id)->row_array() ?: null;
    }

    public function find_by_user($user_id)
    {
        return $this->base_query()->where('patients.user_id', $user_id)->row_array() ?: null;
    }

    /** All patients, optionally filtered by a name search */
    public function list_all($search = null)
    {
        $query = $this->base_query();
        if ($search !== null && $search !== '') {
            $query->like('users.name', '%' . $search . '%');
        }
        return $query->order_by('users.name')->result_array();
    }

    public function update_profile($id, $birthdate, $sex, $address, $contact_no)
    {
        $this->db->table('patients')->where('id', $id)->update([
            'birthdate' => $birthdate, 'sex' => $sex, 'address' => $address, 'contact_no' => $contact_no,
        ]);
    }

    public function has_appointments($id)
    {
        return $this->db->table('appointments')->where('patient_id', $id)->count() > 0;
    }

    /** Delete a patient who has no appointments (also their login and email log) */
    public function delete_completely($id)
    {
        $patient = $this->find($id);
        $this->db->transaction();
        try {
            $this->db->table('patients')->where('id', $id)->delete();
            $this->db->table('email_logs')->where('user_id', $patient['user_id'])->delete();
            $this->db->table('refresh_tokens')->where('user_id', $patient['user_id'])->delete();
            $this->db->table('users')->where('id', $patient['user_id'])->delete();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }
}
