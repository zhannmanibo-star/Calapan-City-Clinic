<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * schedules table. "booked" = appointments that are not cancelled.
 */
class Schedule_model extends Model
{
    protected $table = 'schedules';

    private $select = "SELECT s.id, s.doctor_id, s.`date`, s.start_time, s.end_time, s.max_slots, s.status, s.gcal_event_id,
                              u.name AS doctor_name, d.specialization,
                              (SELECT COUNT(*) FROM appointments a
                               WHERE a.schedule_id = s.id AND a.status <> 'cancelled') AS booked
                       FROM schedules s
                       JOIN doctors d ON d.id = s.doctor_id
                       JOIN users u ON u.id = d.user_id";

    // adds slots_left so the frontend does not have to calculate it
    private function decorate($row)
    {
        if (!$row) return null;
        $row['booked']     = (int) $row['booked'];
        $row['slots_left'] = max(0, (int) $row['max_slots'] - $row['booked']);
        return $row;
    }

    public function find($id)
    {
        return $this->decorate($this->db->raw($this->select . " WHERE s.id = ? LIMIT 1", [$id])->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    /** $from_today = only today and later */
    public function list_for($doctor_id = null, $date = null, $from_today = true)
    {
        $sql = $this->select . " WHERE 1=1";
        $args = [];
        if ($doctor_id) { $sql .= " AND s.doctor_id = ?"; $args[] = $doctor_id; }
        if ($date)      { $sql .= " AND s.`date` = ?"; $args[] = $date; }
        elseif ($from_today) { $sql .= " AND s.`date` >= CURDATE()"; }
        $sql .= " ORDER BY s.`date`, s.start_time";
        return array_map([$this, 'decorate'], $this->db->raw($sql, $args)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** True if the hours overlap another schedule (not closed) of the same doctor that day */
    public function overlaps($doctor_id, $date, $start, $end, $except_id = 0)
    {
        $row = $this->db->raw(
            "SELECT id FROM schedules
             WHERE doctor_id = ? AND `date` = ? AND status <> 'closed' AND id <> ?
               AND start_time < ? AND end_time > ? LIMIT 1",
            [$doctor_id, $date, $except_id, $end, $start]
        )->fetch(PDO::FETCH_ASSOC);
        return (bool) $row;
    }

    public function create($doctor_id, $date, $start, $end, $max_slots)
    {
        $this->db->raw(
            "INSERT INTO schedules (doctor_id, `date`, start_time, end_time, max_slots) VALUES (?, ?, ?, ?, ?)",
            [$doctor_id, $date, $start, $end, $max_slots]
        );
        return (int) $this->db->last_id();
    }

    public function update_fields($id, $start, $end, $max_slots, $status)
    {
        $this->db->raw(
            "UPDATE schedules SET start_time = ?, end_time = ?, max_slots = ?, status = ? WHERE id = ?",
            [$start, $end, $max_slots, $status, $id]
        );
        $this->sync_status($id);
    }

    public function has_appointments($id)
    {
        $row = $this->db->raw("SELECT COUNT(*) AS c FROM appointments WHERE schedule_id = ?", [$id])->fetch(PDO::FETCH_ASSOC);
        return (int) $row['c'] > 0;
    }

    public function remove($id)
    {
        $this->db->raw("DELETE FROM schedules WHERE id = ?", [$id]);
    }

    /** open <-> full depending on bookings; a closed schedule stays closed */
    public function sync_status($id)
    {
        $this->db->raw(
            "UPDATE schedules SET status = CASE
                 WHEN status = 'closed' THEN 'closed'
                 WHEN (SELECT COUNT(*) FROM appointments a WHERE a.schedule_id = schedules.id AND a.status <> 'cancelled') >= max_slots THEN 'full'
                 ELSE 'open' END
             WHERE id = ?",
            [$id]
        );
    }

    /** Active doctors' upcoming open/full schedules for the public Doctors page */
    public function upcoming_by_doctor()
    {
        $by = [];
        foreach ($this->list_for(null, null, true) as $s) {
            if ($s['status'] !== 'closed') $by[$s['doctor_id']][] = $s;
        }
        return $by;
    }
}
