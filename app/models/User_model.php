<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * users table (+ the patient / doctor profile ids that belong to a user).
 */
class User_model extends Model
{
    protected $table = 'users';

    // Safe columns to send to the frontend (never the password)
    private $public_select = "SELECT u.id, u.name, u.email, u.role, u.created_at,
                                     p.id AS patient_id, d.id AS doctor_id
                              FROM users u
                              LEFT JOIN patients p ON p.user_id = u.id
                              LEFT JOIN doctors d  ON d.user_id = u.id";

    public function find_by_email($email)
    {
        return $this->db->raw("SELECT * FROM users WHERE email = ? LIMIT 1", [$email])
                        ->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find_by_google_id($google_id)
    {
        return $this->db->raw("SELECT * FROM users WHERE google_id = ? LIMIT 1", [$google_id])
                        ->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find_public($id)
    {
        return $this->db->raw($this->public_select . " WHERE u.id = ? LIMIT 1", [$id])
                        ->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function all_public()
    {
        return $this->db->raw($this->public_select . " ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Plain insert into users. $password may be null (Google-only accounts). */
    public function create($name, $email, $password, $role, $google_id = null)
    {
        $this->db->raw(
            "INSERT INTO users (google_id, name, email, password, role) VALUES (?, ?, ?, ?, ?)",
            [$google_id, $name, $email, $password === null ? null : password_hash($password, PASSWORD_DEFAULT), $role]
        );
        return (int) $this->db->last_id();
    }

    /** A new patient = one users row + one patients row (saved together) */
    public function register_patient($name, $email, $password, array $profile = [], $google_id = null)
    {
        $this->db->transaction();
        try {
            $id = $this->create($name, $email, $password, 'patient', $google_id);
            $this->db->raw(
                "INSERT INTO patients (user_id, birthdate, sex, address, contact_no) VALUES (?, ?, ?, ?, ?)",
                [$id, $profile['birthdate'] ?? null, $profile['sex'] ?? null, $profile['address'] ?? null, $profile['contact_no'] ?? null]
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function update_basic($id, $name, $email)
    {
        $this->db->raw("UPDATE users SET name = ?, email = ? WHERE id = ?", [$name, $email, $id]);
    }

    public function link_google($id, $google_id)
    {
        $this->db->raw("UPDATE users SET google_id = ? WHERE id = ?", [$google_id, $id]);
    }

    /** Delete an account that has no other records attached */
    public function remove($id)
    {
        $this->db->raw("DELETE FROM refresh_tokens WHERE user_id = ?", [$id]);
        $this->db->raw("DELETE FROM users WHERE id = ?", [$id]);
    }
}
