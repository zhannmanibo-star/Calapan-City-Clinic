<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Patient_model extends Model
{
    protected $table = 'patients';

    private $select = "SELECT p.id, p.user_id, u.name, u.email, p.birthdate, p.sex, p.address, p.contact_no
                       FROM patients p JOIN users u ON u.id = p.user_id";

    public function find($id)
    {
        return $this->db->raw($this->select . " WHERE p.id = ? LIMIT 1", [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find_by_user($user_id)
    {
        return $this->db->raw($this->select . " WHERE p.user_id = ? LIMIT 1", [$user_id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function update_profile($id, $birthdate, $sex, $address, $contact_no)
    {
        $this->db->raw(
            "UPDATE patients SET birthdate = ?, sex = ?, address = ?, contact_no = ? WHERE id = ?",
            [$birthdate, $sex, $address, $contact_no, $id]
        );
    }
}
