<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * patient_records: the doctor's diagnosis and notes, one per appointment.
 */
class Record_model extends Model
{
    protected $table = 'patient_records';
    protected $primary_key = 'id';
    protected $fillable = ['appointment_id', 'doctor_id', 'diagnosis', 'notes', 'visit_date'];
    protected $guarded = ['id'];

    private function base_query()
    {
        return $this->db->table('patient_records')
            ->select('patient_records.id, patient_records.appointment_id, patient_records.doctor_id, patient_records.diagnosis, patient_records.notes, patient_records.visit_date, '
                . 'appointments.patient_id, patient_users.name AS patient_name, doctor_users.name AS doctor_name')
            ->inner_join('appointments', 'appointments.id = patient_records.appointment_id')
            ->inner_join('patients', 'patients.id = appointments.patient_id')
            ->inner_join('users patient_users', 'patient_users.id = patients.user_id')
            ->inner_join('doctors', 'doctors.id = patient_records.doctor_id')
            ->inner_join('users doctor_users', 'doctor_users.id = doctors.user_id');
    }

    public function find($id)
    {
        return $this->base_query()->where('patient_records.id', $id)->row_array() ?: null;
    }

    public function find_by_appointment($appointment_id)
    {
        return $this->base_query()->where('patient_records.appointment_id', $appointment_id)->row_array() ?: null;
    }

    public function for_patient($patient_id)
    {
        return $this->base_query()
            ->where('appointments.patient_id', $patient_id)
            ->order_by('patient_records.visit_date', 'DESC')
            ->result_array();
    }

    public function create($appointment_id, $doctor_id, $diagnosis, $notes, $visit_date)
    {
        $this->db->table('patient_records')->insert([
            'appointment_id' => $appointment_id, 'doctor_id' => $doctor_id,
            'diagnosis' => $diagnosis, 'notes' => $notes, 'visit_date' => $visit_date,
        ]);
        return (int) $this->db->last_id();
    }

    public function update_fields($id, $diagnosis, $notes)
    {
        $this->db->table('patient_records')->where('id', $id)->update(['diagnosis' => $diagnosis, 'notes' => $notes]);
    }

    public function remove($id)
    {
        $this->db->table('patient_records')->where('id', $id)->delete();
    }
}
