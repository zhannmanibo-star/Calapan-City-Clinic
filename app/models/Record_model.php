<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * patient_records: the doctor's diagnosis and notes, one per appointment.
 */
class Record_model extends Model
{
    protected $table = 'patient_records';

    private $select = "SELECT r.id, r.appointment_id, r.doctor_id, r.diagnosis, r.notes, r.visit_date,
                              a.patient_id, pu.name AS patient_name, du.name AS doctor_name
                       FROM patient_records r
                       JOIN appointments a ON a.id = r.appointment_id
                       JOIN patients p ON p.id = a.patient_id
                       JOIN users pu ON pu.id = p.user_id
                       JOIN doctors d ON d.id = r.doctor_id
                       JOIN users du ON du.id = d.user_id";

    public function find($id)
    {
        return $this->db->raw($this->select . " WHERE r.id = ? LIMIT 1", [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find_by_appointment($appointment_id)
    {
        return $this->db->raw($this->select . " WHERE r.appointment_id = ? LIMIT 1", [$appointment_id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function for_patient($patient_id)
    {
        return $this->db->raw($this->select . " WHERE a.patient_id = ? ORDER BY r.visit_date DESC, r.id DESC", [$patient_id])
                        ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($appointment_id, $doctor_id, $diagnosis, $notes, $visit_date)
    {
        $this->db->raw(
            "INSERT INTO patient_records (appointment_id, doctor_id, diagnosis, notes, visit_date) VALUES (?, ?, ?, ?, ?)",
            [$appointment_id, $doctor_id, $diagnosis, $notes, $visit_date]
        );
        return (int) $this->db->last_id();
    }

    public function update_fields($id, $diagnosis, $notes)
    {
        $this->db->raw("UPDATE patient_records SET diagnosis = ?, notes = ? WHERE id = ?", [$diagnosis, $notes, $id]);
    }

    public function remove($id)
    {
        $this->db->raw("DELETE FROM patient_records WHERE id = ?", [$id]);
    }
}
