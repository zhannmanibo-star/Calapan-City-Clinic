<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * doctors table. The doctor's name and email live in the linked users row.
 */
class Doctor_model extends Model
{
    protected $table = 'doctors';

    private $select = "SELECT d.id, d.user_id, u.name, u.email, d.specialization, d.contact_no
                       FROM doctors d JOIN users u ON u.id = d.user_id";

    public function all_doctors()
    {
        return $this->db->raw($this->select . " ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id)
    {
        return $this->db->raw($this->select . " WHERE d.id = ? LIMIT 1", [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find_by_user($user_id)
    {
        return $this->db->raw($this->select . " WHERE d.user_id = ? LIMIT 1", [$user_id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function count_all()
    {
        $row = $this->db->raw("SELECT COUNT(*) AS c FROM doctors")->fetch(PDO::FETCH_ASSOC);
        return (int) $row['c'];
    }

    /** New doctor = one users row (role doctor) + one doctors row (saved together) */
    public function create_with_user($name, $email, $password, $specialization, $contact_no)
    {
        $this->db->transaction();
        try {
            $this->db->raw(
                "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'doctor')",
                [$name, $email, password_hash($password, PASSWORD_DEFAULT)]
            );
            $user_id = (int) $this->db->last_id();
            $this->db->raw(
                "INSERT INTO doctors (user_id, specialization, contact_no) VALUES (?, ?, ?)",
                [$user_id, $specialization, $contact_no]
            );
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
        $this->db->raw("UPDATE users SET name = ?, email = ? WHERE id = ?", [$name, $email, $doctor['user_id']]);
        $this->db->raw("UPDATE doctors SET specialization = ?, contact_no = ? WHERE id = ?", [$specialization, $contact_no, $id]);
    }

    /** Has this doctor ever had appointments or written records? */
    public function has_history($id)
    {
        $a = $this->db->raw(
            "SELECT COUNT(*) AS c FROM appointments a JOIN schedules s ON s.id = a.schedule_id WHERE s.doctor_id = ?", [$id]
        )->fetch(PDO::FETCH_ASSOC);
        $r = $this->db->raw("SELECT COUNT(*) AS c FROM patient_records WHERE doctor_id = ?", [$id])->fetch(PDO::FETCH_ASSOC);
        return ((int) $a['c'] + (int) $r['c']) > 0;
    }

    /** Delete a doctor with no history (also their empty schedules, email logs and login) */
    public function delete_completely($id)
    {
        $doctor = $this->find($id);
        $this->db->transaction();
        try {
            $this->db->raw("DELETE FROM schedules WHERE doctor_id = ?", [$id]);
            $this->db->raw("DELETE FROM doctors WHERE id = ?", [$id]);
            $this->db->raw("DELETE FROM email_logs WHERE user_id = ?", [$doctor['user_id']]);
            $this->db->raw("DELETE FROM refresh_tokens WHERE user_id = ?", [$doctor['user_id']]);
            $this->db->raw("DELETE FROM users WHERE id = ?", [$doctor['user_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }
}
