<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Doctor schedules: clinic hours on a date with a patient limit (max_slots).
 */
class Schedules extends Controller
{
    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model(['Doctor_model', 'Schedule_model']);
    }

    // GET /api/schedules?doctor_id=1&date=2026-11-05   (default: today and later)
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $this->guard->user();
        $q = $this->guard->query();

        $date = $q['date'] ?? null;
        if ($date !== null && !$this->guard->valid_date($date)) $this->guard->fail('Date must look like 2026-11-05.', 422);

        $this->guard->ok(['schedules' => $this->Schedule_model->list_for(!empty($q['doctor_id']) ? (int) $q['doctor_id'] : null, $date)]);
    }

    // GET /api/schedules/{id}
    public function show($id)
    {
        $this->guard->api()->require_method('GET');
        $this->guard->user();
        $s = $this->Schedule_model->find((int) $id);
        if (!$s) $this->guard->fail('Schedule not found.', 404);
        $this->guard->ok(['schedule' => $s]);
    }

    // POST /api/schedules  (staff/admin)   { doctor_id, date, start_time, end_time, max_slots? }
    public function store()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['staff', 'admin']);

        $in    = $this->guard->body();
        $doc   = (int) ($in['doctor_id'] ?? 0);
        $date  = (string) ($in['date'] ?? '');
        $start = $this->guard->norm_time($in['start_time'] ?? '');
        $end   = $this->guard->norm_time($in['end_time'] ?? '');
        $max   = (int) ($in['max_slots'] ?? 20);

        if (!$this->Doctor_model->find($doc))   $this->guard->fail('Doctor not found.', 404);
        if (!$this->guard->valid_date($date))   $this->guard->fail('Date must look like 2026-11-05.', 422);
        if ($date < date('Y-m-d'))              $this->guard->fail('You cannot schedule a past date.', 422);
        if ($start === false || $end === false) $this->guard->fail('Times must look like 08:00.', 422);
        if ($end <= $start)                     $this->guard->fail('End time must be after start time.', 422);
        if ($max < 1 || $max > 200)             $this->guard->fail('Max slots must be from 1 to 200.', 422);
        if ($this->Schedule_model->overlaps($doc, $date, $start, $end)) $this->guard->fail('This doctor already has a schedule that overlaps those hours.', 409);

        $id = $this->Schedule_model->create($doc, $date, $start, $end, $max);
        $this->guard->ok(['message' => 'Schedule added.', 'schedule' => $this->Schedule_model->find($id)], 201);
    }

    // PUT /api/schedules/{id}  (staff/admin)   { start_time?, end_time?, max_slots?, status?: open|closed }
    public function update($id)
    {
        $this->guard->api()->require_method('PUT');
        $this->guard->require_role(['staff', 'admin']);

        $s = $this->Schedule_model->find((int) $id);
        if (!$s) $this->guard->fail('Schedule not found.', 404);

        $in    = $this->guard->body();
        $start = $this->guard->norm_time($in['start_time'] ?? $s['start_time']);
        $end   = $this->guard->norm_time($in['end_time'] ?? $s['end_time']);
        $max   = (int) ($in['max_slots'] ?? $s['max_slots']);
        $status = (string) ($in['status'] ?? ($s['status'] === 'closed' ? 'closed' : 'open'));

        if ($start === false || $end === false) $this->guard->fail('Times must look like 08:00.', 422);
        if ($end <= $start)                     $this->guard->fail('End time must be after start time.', 422);
        if ($max < 1 || $max > 200)             $this->guard->fail('Max slots must be from 1 to 200.', 422);
        if ($max < $s['booked'])                $this->guard->fail("Max slots cannot be lower than the {$s['booked']} patients already booked.", 422);
        if (!in_array($status, ['open', 'closed'], true)) $this->guard->fail('Status must be open or closed.', 422);
        if ($this->Schedule_model->overlaps($s['doctor_id'], $s['date'], $start, $end, $s['id'])) $this->guard->fail('Those hours overlap another schedule.', 409);

        $this->Schedule_model->update_fields($s['id'], $start, $end, $max, $status);
        $this->guard->ok(['message' => 'Schedule updated.', 'schedule' => $this->Schedule_model->find($s['id'])]);
    }

    // DELETE /api/schedules/{id}  (staff/admin)
    // No appointments -> deleted. Cancelled-only history -> closed. Active appointments -> refused.
    public function destroy($id)
    {
        $this->guard->api()->require_method('DELETE');
        $this->guard->require_role(['staff', 'admin']);

        $s = $this->Schedule_model->find((int) $id);
        if (!$s) $this->guard->fail('Schedule not found.', 404);

        if ($s['booked'] > 0) $this->guard->fail('This schedule still has booked patients. Cancel their appointments first.', 409);

        if ($this->Schedule_model->has_appointments($s['id'])) {
            $this->Schedule_model->update_fields($s['id'], $s['start_time'], $s['end_time'], $s['max_slots'], 'closed');
            $this->guard->ok(['message' => 'Schedule closed (it has cancelled appointments on record).', 'deleted' => false]);
        }

        $this->Schedule_model->remove($s['id']);
        $this->guard->ok(['message' => 'Schedule deleted.', 'deleted' => true]);
    }
}
