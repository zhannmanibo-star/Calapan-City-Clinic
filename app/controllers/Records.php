<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Patient records (diagnosis + notes), one per appointment.
 */
class Records extends Controller
{
    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model(['Record_model', 'Appointment_model', 'Doctor_model', 'Patient_model']);
    }

    // Is this the doctor who owns the record / appointment? (admin and staff always pass)
    private function doctor_owns($payload, $doctor_id)
    {
        $d = $this->Doctor_model->find_by_user($payload['sub']);
        return $d && (int) $d['id'] === (int) $doctor_id;
    }

    // GET /api/records?patient_id=5   (patients automatically get their own)
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->user();

        if ($payload['role'] === 'patient') {
            $p = $this->Patient_model->find_by_user($payload['sub']);
            $patient_id = $p ? (int) $p['id'] : 0;
        } else {
            $q = $this->guard->query();
            $patient_id = (int) ($q['patient_id'] ?? 0);
            if ($patient_id < 1) $this->guard->fail('Provide ?patient_id=.', 422);
        }
        $this->guard->ok(['records' => $this->Record_model->for_patient($patient_id)]);
    }

    // GET /api/records/{id}
    public function show($id)
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->user();
        $r = $this->Record_model->find((int) $id);
        if (!$r) $this->guard->fail('Record not found.', 404);

        if ($payload['role'] === 'patient') {
            $p = $this->Patient_model->find_by_user($payload['sub']);
            if (!$p || (int) $p['id'] !== (int) $r['patient_id']) $this->guard->fail('Access denied', 403);
        }
        $this->guard->ok(['record' => $r]);
    }

    // POST /api/records  (doctor, staff, admin)   { appointment_id, diagnosis?, notes?, visit_date? }
    public function store()
    {
        $this->guard->api()->require_method('POST');
        $payload = $this->guard->require_role(['doctor', 'staff', 'admin']);

        $in   = $this->guard->body();
        $appt = $this->Appointment_model->find((int) ($in['appointment_id'] ?? 0));
        if (!$appt) $this->guard->fail('Appointment not found.', 404);
        if ($payload['role'] === 'doctor' && !$this->doctor_owns($payload, $appt['doctor_id'])) $this->guard->fail('Access denied', 403);
        if ($appt['status'] === 'cancelled') $this->guard->fail('This appointment was cancelled.', 422);
        if ($this->Record_model->find_by_appointment($appt['id'])) $this->guard->fail('This appointment already has a record. Edit it instead.', 409);

        $diagnosis = trim($in['diagnosis'] ?? '');
        $notes     = trim($in['notes'] ?? '');
        $visit     = trim($in['visit_date'] ?? $appt['appointment_date']);
        if ($diagnosis === '' && $notes === '') $this->guard->fail('Write a diagnosis or notes.', 422);
        if (mb_strlen($diagnosis) > 5000 || mb_strlen($notes) > 5000) $this->guard->fail('Diagnosis and notes are limited to 5000 characters.', 422);
        if (!$this->guard->valid_date($visit)) $this->guard->fail('Visit date must look like 2026-11-05.', 422);

        $id = $this->Record_model->create($appt['id'], $appt['doctor_id'], $diagnosis ?: null, $notes ?: null, $visit);
        $this->guard->ok(['message' => 'Record saved.', 'record' => $this->Record_model->find($id)], 201);
    }

    // PUT /api/records/{id}  (the doctor who wrote it, staff, admin)   { diagnosis?, notes? }
    public function update($id)
    {
        $this->guard->api()->require_method('PUT');
        $payload = $this->guard->require_role(['doctor', 'staff', 'admin']);
        $r = $this->Record_model->find((int) $id);
        if (!$r) $this->guard->fail('Record not found.', 404);
        if ($payload['role'] === 'doctor' && !$this->doctor_owns($payload, $r['doctor_id'])) $this->guard->fail('Access denied', 403);

        $in        = $this->guard->body();
        $diagnosis = trim($in['diagnosis'] ?? (string) $r['diagnosis']);
        $notes     = trim($in['notes'] ?? (string) $r['notes']);
        if ($diagnosis === '' && $notes === '') $this->guard->fail('Write a diagnosis or notes.', 422);
        if (mb_strlen($diagnosis) > 5000 || mb_strlen($notes) > 5000) $this->guard->fail('Diagnosis and notes are limited to 5000 characters.', 422);

        $this->Record_model->update_fields($r['id'], $diagnosis ?: null, $notes ?: null);
        $this->guard->ok(['message' => 'Record updated.', 'record' => $this->Record_model->find($r['id'])]);
    }

    // DELETE /api/records/{id}  (the doctor who wrote it, or admin)
    public function destroy($id)
    {
        $this->guard->api()->require_method('DELETE');
        $payload = $this->guard->require_role(['doctor', 'admin']);
        $r = $this->Record_model->find((int) $id);
        if (!$r) $this->guard->fail('Record not found.', 404);
        if ($payload['role'] === 'doctor' && !$this->doctor_owns($payload, $r['doctor_id'])) $this->guard->fail('Access denied', 403);

        $this->Record_model->remove($r['id']);
        $this->guard->ok(['message' => 'Record deleted.']);
    }
}
