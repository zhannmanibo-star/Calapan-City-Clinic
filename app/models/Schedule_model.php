<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * schedules table. "booked" = appointments that are not cancelled.
 */
class Schedule_model extends Model
{
    protected $table = 'schedules';
    protected $primary_key = 'id';
    protected $fillable = ['doctor_id', 'date', 'start_time', 'end_time', 'max_slots', 'status', 'gcal_event_id'];
    protected $guarded = ['id'];

    private function base_query()
    {
        return $this->db->table('schedules')
            ->select('schedules.id, schedules.doctor_id, schedules.date, schedules.start_time, schedules.end_time, schedules.max_slots, schedules.status, schedules.gcal_event_id, users.name AS doctor_name, doctors.specialization')
            ->inner_join('doctors', 'doctors.id = schedules.doctor_id')
            ->inner_join('users', 'users.id = doctors.user_id');
    }

    /** Number of booked (not cancelled) appointments per schedule id */
    private function booked_map(array $schedule_ids)
    {
        if (!$schedule_ids) return [];
        $rows = $this->db->table('appointments')
            ->select('schedule_id, COUNT(*) AS booked')
            ->in('schedule_id', $schedule_ids)
            ->where('status', '<>', 'cancelled')
            ->group_by('schedule_id')
            ->result_array();
        return array_column($rows, 'booked', 'schedule_id');
    }

    // adds booked and slots_left so the frontend does not have to calculate them
    private function decorate(array $rows)
    {
        $booked = $this->booked_map(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['booked']     = (int) ($booked[$r['id']] ?? 0);
            $r['slots_left'] = max(0, (int) $r['max_slots'] - $r['booked']);
        }
        return $rows;
    }

    public function find($id)
    {
        $row = $this->base_query()->where('schedules.id', $id)->row_array();
        return $row ? $this->decorate([$row])[0] : null;
    }

    /** Default: today and later. Pass $date to see one specific day. */
    public function list_for($doctor_id = null, $date = null)
    {
        $query = $this->base_query();
        if ($doctor_id) $query->where('schedules.doctor_id', $doctor_id);
        if ($date) {
            $query->where('schedules.date', $date);
        } else {
            $query->where('schedules.date', '>=', date('Y-m-d'));
        }
        $rows = $query->order_by('schedules.date')->result_array();

        usort($rows, fn($a, $b) => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]);
        return $this->decorate($rows);
    }

    /** True if the hours overlap another schedule (not closed) of the same doctor that day */
    public function overlaps($doctor_id, $date, $start, $end, $except_id = 0)
    {
        $row = $this->db->table('schedules')
            ->where('doctor_id', $doctor_id)
            ->where('date', $date)
            ->where('status', '<>', 'closed')
            ->where('id', '<>', $except_id)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->row_array();
        return (bool) $row;
    }

    public function create($doctor_id, $date, $start, $end, $max_slots)
    {
        $this->db->table('schedules')->insert([
            'doctor_id' => $doctor_id, 'date' => $date,
            'start_time' => $start, 'end_time' => $end, 'max_slots' => $max_slots,
        ]);
        return (int) $this->db->last_id();
    }

    public function update_fields($id, $start, $end, $max_slots, $status)
    {
        $this->db->table('schedules')->where('id', $id)->update([
            'start_time' => $start, 'end_time' => $end, 'max_slots' => $max_slots, 'status' => $status,
        ]);
        $this->sync_status($id);
    }

    public function has_appointments($id)
    {
        return $this->db->table('appointments')->where('schedule_id', $id)->count() > 0;
    }

    public function remove($id)
    {
        $this->db->table('schedules')->where('id', $id)->delete();
    }

    /** open <-> full depending on bookings; a closed schedule stays closed */
    public function sync_status($id)
    {
        $schedule = $this->db->table('schedules')->where('id', $id)->row_array();
        if (!$schedule || $schedule['status'] === 'closed') return;

        $booked = $this->db->table('appointments')
            ->where('schedule_id', $id)
            ->where('status', '<>', 'cancelled')
            ->count();
        $status = ($booked >= (int) $schedule['max_slots']) ? 'full' : 'open';

        $this->db->table('schedules')->where('id', $id)->update(['status' => $status]);
    }

    /** Upcoming schedules (not closed) grouped by doctor id */
    public function upcoming_by_doctor()
    {
        $grouped = [];
        foreach ($this->list_for() as $s) {
            if ($s['status'] !== 'closed') $grouped[$s['doctor_id']][] = $s;
        }
        return $grouped;
    }
}
