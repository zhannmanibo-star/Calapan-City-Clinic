<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * appointments (+ the queue ticket issued for each one).
 */
class Appointment_model extends Model
{
    protected $table = 'appointments';

    private $select = "SELECT a.id, a.patient_id, a.schedule_id, a.status, a.reason, a.gcal_event_id, a.created_at,
                              s.doctor_id, s.`date` AS appointment_date, s.start_time, s.end_time, s.max_slots,
                              pu.name AS patient_name, pu.email AS patient_email, p.user_id AS patient_user_id,
                              du.name AS doctor_name, d.specialization, d.user_id AS doctor_user_id,
                              q.id AS ticket_id, q.queue_number, q.qr_code, q.status AS ticket_status, q.called_at
                       FROM appointments a
                       JOIN patients p ON p.id = a.patient_id
                       JOIN users pu ON pu.id = p.user_id
                       JOIN schedules s ON s.id = a.schedule_id
                       JOIN doctors d ON d.id = s.doctor_id
                       JOIN users du ON du.id = d.user_id
                       LEFT JOIN queue_tickets q ON q.appointment_id = a.id";

    public function find($id)
    {
        return $this->db->raw($this->select . " WHERE a.id = ? LIMIT 1", [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** $f may contain: patient_id, doctor_id, schedule_id, date, status */
    public function list_filtered(array $f)
    {
        $sql = $this->select . " WHERE 1=1";
        $args = [];
        foreach (['patient_id' => 'a.patient_id', 'doctor_id' => 's.doctor_id', 'schedule_id' => 'a.schedule_id',
                  'date' => 's.`date`', 'status' => 'a.status'] as $key => $col) {
            if (!empty($f[$key])) { $sql .= " AND $col = ?"; $args[] = $f[$key]; }
        }
        $sql .= " ORDER BY s.`date`, s.start_time, q.queue_number";
        return $this->db->raw($sql, $args)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count_for_date($date)
    {
        $row = $this->db->raw(
            "SELECT COUNT(*) AS c FROM appointments a JOIN schedules s ON s.id = a.schedule_id
             WHERE s.`date` = ? AND a.status <> 'cancelled'", [$date]
        )->fetch(PDO::FETCH_ASSOC);
        return (int) $row['c'];
    }

    /**
     * Book a place on a schedule and issue the queue ticket.
     * The schedule row is locked first, so patients booking at the same moment are
     * handled one after the other (no overbooking, no duplicate queue numbers).
     * Returns ['id' => appointment id] or ['error' => message, 'code' => http code].
     */
    public function book($patient_id, $schedule_id, $reason)
    {
        $this->db->transaction();
        try {
            $s = $this->db->raw("SELECT * FROM schedules WHERE id = ? FOR UPDATE", [$schedule_id])->fetch(PDO::FETCH_ASSOC);
            if (!$s || $s['status'] === 'closed') {
                $this->db->roll_back();
                return ['error' => 'This schedule is not available.', 'code' => 409];
            }

            $c = $this->db->raw(
                "SELECT COUNT(*) AS c FROM appointments WHERE schedule_id = ? AND status <> 'cancelled'", [$schedule_id]
            )->fetch(PDO::FETCH_ASSOC);
            if ((int) $c['c'] >= (int) $s['max_slots']) {
                $this->db->roll_back();
                return ['error' => 'This schedule is already full. Please choose another date.', 'code' => 409];
            }

            $dup = $this->db->raw(
                "SELECT id FROM appointments WHERE patient_id = ? AND schedule_id = ? AND status <> 'cancelled' LIMIT 1",
                [$patient_id, $schedule_id]
            )->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                $this->db->roll_back();
                return ['error' => 'You already have an appointment on this schedule.', 'code' => 409];
            }

            // Queue numbers never repeat within a schedule, even after cancellations
            $m = $this->db->raw(
                "SELECT COALESCE(MAX(q.queue_number), 0) AS m FROM queue_tickets q
                 JOIN appointments a ON a.id = q.appointment_id WHERE a.schedule_id = ?", [$schedule_id]
            )->fetch(PDO::FETCH_ASSOC);

            $this->db->raw("INSERT INTO appointments (patient_id, schedule_id, reason) VALUES (?, ?, ?)", [$patient_id, $schedule_id, $reason]);
            $id = (int) $this->db->last_id();
            $this->db->raw(
                "INSERT INTO queue_tickets (appointment_id, queue_number, qr_code) VALUES (?, ?, ?)",
                [$id, (int) $m['m'] + 1, bin2hex(random_bytes(16))]
            );
            $this->db->commit();

            $this->db->raw(
                "UPDATE schedules SET status = CASE WHEN (SELECT COUNT(*) FROM appointments a WHERE a.schedule_id = schedules.id AND a.status <> 'cancelled') >= max_slots THEN 'full' ELSE 'open' END
                 WHERE id = ? AND status <> 'closed'", [$schedule_id]
            );
            return ['id' => $id];
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Change the appointment status. Cancelling also cancels the ticket and frees the place. */
    public function set_status($id, $status)
    {
        $appt = $this->find($id);
        $this->db->raw("UPDATE appointments SET status = ? WHERE id = ?", [$status, $id]);
        if ($status === 'cancelled') {
            $this->db->raw("UPDATE queue_tickets SET status = 'cancelled' WHERE appointment_id = ?", [$id]);
        }
        $this->db->raw(
            "UPDATE schedules SET status = CASE WHEN status = 'closed' THEN 'closed'
                 WHEN (SELECT COUNT(*) FROM appointments a WHERE a.schedule_id = schedules.id AND a.status <> 'cancelled') >= max_slots THEN 'full' ELSE 'open' END
             WHERE id = ?", [$appt['schedule_id']]
        );
    }
}
