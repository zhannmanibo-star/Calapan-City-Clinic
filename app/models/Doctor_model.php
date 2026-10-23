<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * doctors table. The doctor's name and email live in the linked users row.
 */
class Doctor_model extends Model
{
    protected $table = 'doctors';
    protected $primary_key = 'id';
    protected $fillable = ['user_id', 'specialization', 'contact_no'];
    protected $guarded = ['id'];

    private function base_query()
    {
        return $this->db->table('doctors')
            ->select('doctors.id, doctors.user_id, users.name, users.email, doctors.specialization, doctors.contact_no')
            ->inner_join('users', 'users.id = doctors.user_id');
    }

    public function all_doctors()
    {
        return $this->base_query()->order_by('users.name')->result_array();
    }

    public function find($id)
    {
        return $this->base_query()->where('doctors.id', $id)->row_array() ?: null;
    }

    public function find_by_user($user_id)
    {
        return $this->base_query()->where('doctors.user_id', $user_id)->row_array() ?: null;
    }

    public function count_all()
    {
        return (int) $this->db->table('doctors')->count();
    }

    /** New doctor = one users row (role doctor) + one doctors row (saved together) */
    public function create_with_user($name, $email, $password, $specialization, $contact_no)
    {
        $this->db->transaction();
        try {
            $this->db->table('users')->insert([
                'name' => $name, 'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'doctor',
            ]);
            $user_id = (int) $this->db->last_id();

            $this->db->table('doctors')->insert([
                'user_id' => $user_id, 'specialization' => $specialization, 'contact_no' => $contact_no,
            ]);
            $id = (int) $this->db->last_id();

            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function update_profile($id, $name, $email, $specialization, $contact_no)
    {
        $doctor = $this->find($id);
        $this->db->table('users')->where('id', $doctor['user_id'])->update(['name' => $name, 'email' => $email]);
        $this->db->table('doctors')->where('id', $id)->update(['specialization' => $specialization, 'contact_no' => $contact_no]);
    }

    /** Has this doctor ever had appointments or written records? */
    public function has_history($id)
    {
        $schedule_ids = array_column($this->db->table('schedules')->select('id')->where('doctor_id', $id)->result_array(), 'id');
        $appointments = $schedule_ids ? $this->db->table('appointments')->in('schedule_id', $schedule_ids)->count() : 0;
        $records      = $this->db->table('patient_records')->where('doctor_id', $id)->count();
        return ($appointments + $records) > 0;
    }

    /** Delete a doctor with no history (also their empty schedules, email log and login) */
    public function delete_completely($id)
    {
        $doctor = $this->find($id);
        $this->db->transaction();
        try {
            $this->db->table('schedules')->where('doctor_id', $id)->delete();
            $this->db->table('doctors')->where('id', $id)->delete();
            $this->db->table('email_logs')->where('user_id', $doctor['user_id'])->delete();
            $this->db->table('refresh_tokens')->where('user_id', $doctor['user_id'])->delete();
            $this->db->table('users')->where('id', $doctor['user_id'])->delete();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }
}
