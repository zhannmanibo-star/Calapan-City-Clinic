<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Queue + QR check-in (staff) and the public queue board.
 *
 * ticket status:  issued -> waiting (checked in by QR) -> called -> served
 *                 skipped can happen before serving
 */
class Queue extends Controller
{
    private $transitions = [
        'issued'  => ['waiting', 'skipped'],
        'waiting' => ['called', 'skipped'],
        'called'  => ['served', 'skipped'],
    ];

    // What the appointment status becomes when the ticket changes
    private $appointment_status = ['waiting' => 'confirmed', 'called' => 'confirmed', 'served' => 'completed', 'skipped' => 'no_show'];

    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model(['Queue_model', 'Doctor_model']);
    }

    private function out($ticket)
    {
        return $this->guard->with_estimate($ticket);
    }

    // A doctor may only touch tickets of their own patients
    private function check_doctor($payload, $ticket)
    {
        if ($payload['role'] === 'doctor' && (int) $ticket['doctor_user_id'] !== (int) $payload['sub']) {
            $this->guard->fail('Access denied', 403);
        }
    }

    // GET /api/queue?schedule_id=&doctor_id=&date=   (staff, doctor, admin)
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->require_role(['staff', 'doctor', 'admin']);
        $q = $this->guard->query();

        $f = [];
        if (!empty($q['schedule_id'])) $f['schedule_id'] = (int) $q['schedule_id'];
        if (!empty($q['date'])) {
            if (!$this->guard->valid_date($q['date'])) $this->guard->fail('Date must look like 2026-11-05.', 422);
            $f['date'] = $q['date'];
        }
        if ($payload['role'] === 'doctor') {
            $d = $this->Doctor_model->find_by_user($payload['sub']);
            $f['doctor_id'] = $d ? (int) $d['id'] : -1;
        } elseif (!empty($q['doctor_id'])) {
            $f['doctor_id'] = (int) $q['doctor_id'];
        }
        if (empty($f['date']) && empty($f['schedule_id'])) $f['date'] = date('Y-m-d');   // default: today

        $this->guard->ok(['queue' => array_map([$this, 'out'], $this->Queue_model->list_filtered($f))]);
    }

    // GET /api/queue/ticket/{qr_code}   (staff, doctor, admin) - look up a scanned QR code
    public function ticket($qr)
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->require_role(['staff', 'doctor', 'admin']);
        $ticket = $this->Queue_model->find_by_qr((string) $qr);
        if (!$ticket) $this->guard->fail('Ticket not found.', 404);
        $this->check_doctor($payload, $ticket);
        $this->guard->ok(['ticket' => $this->out($ticket)]);
    }

    // POST /api/queue/check-in   (staff/admin)   { qr_code }
    // The patient arrives at the clinic and the QR code is scanned.
    public function check_in()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['staff', 'admin']);

        $in = $this->guard->body();
        $ticket = $this->Queue_model->find_by_qr((string) ($in['qr_code'] ?? ''));
        if (!$ticket) $this->guard->fail('Ticket not found.', 404);

        if ($ticket['ticket_status'] === 'cancelled') $this->guard->fail('This appointment was cancelled.', 409);
        if ($ticket['ticket_status'] !== 'issued')    $this->guard->fail("This ticket is already '{$ticket['ticket_status']}'.", 409);
        if ($ticket['appointment_date'] !== date('Y-m-d')) {
            $this->guard->fail("This ticket is for {$ticket['appointment_date']}, not today.", 422);
        }

        $this->Queue_model->set_ticket_status($ticket['ticket_id'], 'waiting', 'confirmed');
        $this->guard->ok(['message' => 'Patient checked in.', 'ticket' => $this->out($this->Queue_model->find_ticket($ticket['ticket_id']))]);
    }

    // PATCH /api/queue/{ticket_id}/status   (staff, admin, or the doctor)   { status: called|served|skipped }
    public function update_status($id)
    {
        $this->guard->api()->require_method('PATCH');
        $payload = $this->guard->require_role(['staff', 'admin', 'doctor']);

        $ticket = $this->Queue_model->find_ticket((int) $id);
        if (!$ticket) $this->guard->fail('Ticket not found.', 404);
        $this->check_doctor($payload, $ticket);

        $in      = $this->guard->body();
        $status  = (string) ($in['status'] ?? '');
        $allowed = $this->transitions[$ticket['ticket_status']] ?? [];
        if (!in_array($status, $allowed, true) || $status === 'waiting') {
            $this->guard->fail("Cannot change from '{$ticket['ticket_status']}' to '{$status}'.", 422);
        }

        $this->Queue_model->set_ticket_status($ticket['ticket_id'], $status, $this->appointment_status[$status] ?? null);
        $this->guard->ok(['message' => 'Queue updated.', 'ticket' => $this->out($this->Queue_model->find_ticket($ticket['ticket_id']))]);
    }

    // POST /api/queue/call-next   (staff, admin, doctor)   { schedule_id }
    // Calls the waiting patient with the lowest queue number.
    public function call_next()
    {
        $this->guard->api()->require_method('POST');
        $payload = $this->guard->require_role(['staff', 'admin', 'doctor']);

        $in  = $this->guard->body();
        $sid = (int) ($in['schedule_id'] ?? 0);
        if ($sid < 1) $this->guard->fail('schedule_id is required.', 422);

        $next = null;
        foreach ($this->Queue_model->list_filtered(['schedule_id' => $sid]) as $t) {
            if ($t['ticket_status'] === 'waiting') { $next = $t; break; }
        }
        if (!$next) $this->guard->fail('No patients are waiting.', 404);
        $this->check_doctor($payload, $next);

        $this->Queue_model->set_ticket_status($next['ticket_id'], 'called', 'confirmed');
        $this->guard->ok(['message' => 'Next patient called.', 'ticket' => $this->out($this->Queue_model->find_ticket($next['ticket_id']))]);
    }

    // GET /api/queue/board?date=   PUBLIC (no login). Numbers and statuses only, no names.
    public function board()
    {
        $this->guard->api()->require_method('GET');
        $q = $this->guard->query();
        $date = $q['date'] ?? date('Y-m-d');
        if (!$this->guard->valid_date($date)) $this->guard->fail('Date must look like 2026-11-05.', 422);

        $groups = [];
        foreach ($this->Queue_model->public_board($date) as $r) {
            $key = $r['schedule_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['schedule_id' => (int) $r['schedule_id'], 'doctor_name' => $r['doctor_name'],
                                 'start_time' => $r['start_time'], 'end_time' => $r['end_time'],
                                 'now_serving' => null, 'waiting' => 0, 'tickets' => []];
            }
            if ($r['ticket_status'] === 'called')  $groups[$key]['now_serving'] = (int) $r['queue_number'];
            if ($r['ticket_status'] === 'waiting') $groups[$key]['waiting']++;
            $groups[$key]['tickets'][] = ['queue_number' => (int) $r['queue_number'], 'status' => $r['ticket_status']];
        }
        $this->guard->ok(['date' => $date, 'schedules' => array_values($groups)]);
    }
}
