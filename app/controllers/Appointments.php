<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Booking an appointment (which issues the queue number + QR code), viewing and cancelling.
 */
class Appointments extends Controller
{
    // Allowed manual status changes by staff (completed / no_show come from the queue)
    private $transitions = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['cancelled'],
    ];

    public function before_action()
    {
        $this->call->library(['guard', 'mailer']);
        $this->call->model(['Appointment_model', 'Schedule_model', 'Patient_model', 'Doctor_model']);
    }

    private function out($appt)
    {
        return $this->guard->with_estimate($appt);
    }

    private function can_access($payload, $appt)
    {
        if (in_array($payload['role'], ['admin', 'staff'], true)) return true;
        if ($payload['role'] === 'patient') return (int) $appt['patient_user_id'] === (int) $payload['sub'];
        if ($payload['role'] === 'doctor')  return (int) $appt['doctor_user_id']  === (int) $payload['sub'];
        return false;
    }

    private function load_or_404($id)
    {
        $appt = $this->Appointment_model->find((int) $id);
        if (!$appt) $this->guard->fail('Appointment not found.', 404);
        return $appt;
    }

    private function email_patient($appt, $type, $subject, $body)
    {
        $this->mailer->send($appt['patient_user_id'], $appt['id'], $type, $appt['patient_email'], $subject, $body);
    }

    // GET /api/appointments?date=&status=&doctor_id=&schedule_id=
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->user();
        $q = $this->guard->query();

        $filters = ['status' => $q['status'] ?? null];
        if (!empty($q['date'])) {
            if (!$this->guard->valid_date($q['date'])) $this->guard->fail('Date must look like 2026-11-05.', 422);
            $filters['date'] = $q['date'];
        }
        if (!empty($q['schedule_id'])) $filters['schedule_id'] = (int) $q['schedule_id'];

        if ($payload['role'] === 'patient') {
            $p = $this->Patient_model->find_by_user($payload['sub']);
            $filters['patient_id'] = $p ? (int) $p['id'] : -1;
        } elseif ($payload['role'] === 'doctor') {
            $d = $this->Doctor_model->find_by_user($payload['sub']);
            $filters['doctor_id'] = $d ? (int) $d['id'] : -1;
        } elseif (!empty($q['doctor_id'])) {
            $filters['doctor_id'] = (int) $q['doctor_id'];
        }

        $rows = array_map([$this, 'out'], $this->Appointment_model->list_filtered($filters));
        $this->guard->ok(['appointments' => $rows]);
    }

    // GET /api/appointments/{id}
    public function show($id)
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->user();
        $appt = $this->load_or_404($id);
        if (!$this->can_access($payload, $appt)) $this->guard->fail('Access denied', 403);
        $this->guard->ok(['appointment' => $this->out($appt)]);
    }

    // POST /api/appointments  (patients)   { schedule_id, reason? }
    public function store()
    {
        $this->guard->api()->require_method('POST');
        $payload = $this->guard->require_role(['patient']);

        $in     = $this->guard->body();
        $sid    = (int) ($in['schedule_id'] ?? 0);
        $reason = trim($in['reason'] ?? '');
        if (mb_strlen($reason) > 1000) $this->guard->fail('Reason is too long (1000 characters max).', 422);

        $schedule = $this->Schedule_model->find($sid);
        if (!$schedule) $this->guard->fail('Schedule not found.', 404);
        if (strtotime($schedule['date'] . ' ' . $schedule['end_time']) <= time()) $this->guard->fail('That schedule has already ended.', 422);

        $patient = $this->Patient_model->find_by_user($payload['sub']);
        $result  = $this->Appointment_model->book((int) $patient['id'], $sid, $reason ?: null);
        if (isset($result['error'])) $this->guard->fail($result['error'], $result['code']);

        $appt = $this->out($this->Appointment_model->find($result['id']));
        $this->email_patient($appt, 'booking_confirmation', 'Your clinic appointment is booked',
            "Hello {$appt['patient_name']},\n\nYour appointment with {$appt['doctor_name']} is booked.\n"
            . "Date: {$appt['appointment_date']}\nQueue number: {$appt['queue_number']}\n"
            . "Estimated time: {$appt['estimated_time']}\n\nShow your QR ticket at the clinic.\n\n- Calapan City Health and Sanitation Department");

        $this->guard->ok(['message' => 'Appointment booked.', 'appointment' => $appt], 201);
    }

    // PUT /api/appointments/{id}  (the patient, or staff/admin)   { reason }
    // Only appointments that are still pending or confirmed can be edited.
    public function update($id)
    {
        $this->guard->api()->require_method('PUT');
        $payload = $this->guard->require_role(['patient', 'staff', 'admin']);
        $appt = $this->load_or_404($id);
        if (!$this->can_access($payload, $appt)) $this->guard->fail('Access denied', 403);
        if (!in_array($appt['status'], ['pending', 'confirmed'], true)) $this->guard->fail('This appointment can no longer be edited.', 422);

        $in     = $this->guard->body();
        $reason = trim($in['reason'] ?? '');
        if (mb_strlen($reason) > 1000) $this->guard->fail('Reason is too long (1000 characters max).', 422);

        $this->Appointment_model->update_reason($appt['id'], $reason ?: null);
        $this->guard->ok(['message' => 'Appointment updated.', 'appointment' => $this->out($this->Appointment_model->find($appt['id']))]);
    }

    // DELETE /api/appointments/{id}  (staff/admin)
    // Safeguard: completed visits and appointments with a patient record are kept as history.
    public function destroy($id)
    {
        $this->guard->api()->require_method('DELETE');
        $this->guard->require_role(['staff', 'admin']);
        $appt = $this->load_or_404($id);

        if ($appt['status'] === 'completed' || $this->Appointment_model->has_record($appt['id'])) {
            $this->guard->fail('Completed visits and appointments with a record are kept as history.', 409);
        }
        $this->Appointment_model->remove($appt['id']);
        $this->guard->ok(['message' => 'Appointment deleted.']);
    }

    // PATCH /api/appointments/{id}/status  (staff/admin)   { status: confirmed|cancelled }
    public function update_status($id)
    {
        $this->guard->api()->require_method('PATCH');
        $this->guard->require_role(['staff', 'admin']);
        $appt = $this->load_or_404($id);

        $in      = $this->guard->body();
        $status  = (string) ($in['status'] ?? '');
        $allowed = $this->transitions[$appt['status']] ?? [];
        if (!in_array($status, $allowed, true)) $this->guard->fail("Cannot change from '{$appt['status']}' to '{$status}'.", 422);

        $this->Appointment_model->set_status($appt['id'], $status);
        $updated = $this->out($this->Appointment_model->find($appt['id']));
        if ($status === 'cancelled') {
            $this->email_patient($updated, 'cancellation', 'Your clinic appointment was cancelled',
                "Hello {$updated['patient_name']},\n\nYour appointment with {$updated['doctor_name']} on {$updated['appointment_date']} was cancelled by the clinic. Please book another date.\n\n- Calapan City Health and Sanitation Department");
        }
        $this->guard->ok(['message' => 'Status updated.', 'appointment' => $updated]);
    }

    // POST /api/appointments/{id}/cancel  (the patient who booked it)
    public function cancel($id)
    {
        $this->guard->api()->require_method('POST');
        $payload = $this->guard->require_role(['patient']);
        $appt = $this->load_or_404($id);
        if (!$this->can_access($payload, $appt)) $this->guard->fail('Access denied', 403);

        if (!in_array($appt['status'], ['pending', 'confirmed'], true) || !in_array($appt['ticket_status'], ['issued', 'waiting'], true)) {
            $this->guard->fail('This appointment can no longer be cancelled.', 422);
        }
        if (strtotime($appt['appointment_date'] . ' ' . $appt['end_time']) <= time()) {
            $this->guard->fail('This appointment has already passed.', 422);
        }

        $this->Appointment_model->set_status($appt['id'], 'cancelled');
        $updated = $this->out($this->Appointment_model->find($appt['id']));
        $this->email_patient($updated, 'cancellation', 'You cancelled your clinic appointment',
            "Hello {$updated['patient_name']},\n\nYour appointment with {$updated['doctor_name']} on {$updated['appointment_date']} was cancelled.\n\n- Calapan City Health and Sanitation Department");
        $this->guard->ok(['message' => 'Appointment cancelled.', 'appointment' => $updated]);
    }
}
