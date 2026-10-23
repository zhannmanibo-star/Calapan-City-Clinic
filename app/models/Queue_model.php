<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * queue_tickets: check-in by QR code, calling patients, public queue board.
 */
class Queue_model extends Model
{
    protected $table = 'queue_tickets';

    private $select = "SELECT q.id AS ticket_id, q.appointment_id, q.queue_number, q.qr_code, q.status AS ticket_status, q.called_at,
                              a.status AS appointment_status, a.patient_id, a.reason,
                              s.id AS schedule_id, s.doctor_id, s.`date` AS appointment_date, s.start_time, s.end_time, s.max_slots,
                              pu.name AS patient_name, du.name AS doctor_name, d.user_id AS doctor_user_id
                       FROM queue_tickets q
                       JOIN appointments a ON a.id = q.appointment_id
                       JOIN patients p ON p.id = a.patient_id
                       JOIN users pu ON pu.id = p.user_id
                       JOIN schedules s ON s.id = a.schedule_id
                       JOIN doctors d ON d.id = s.doctor_id
                       JOIN users du ON du.id = d.user_id";

    public function find_ticket($id)
    {
        return $this->db->raw($this->select . " WHERE q.id = ? LIMIT 1", [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function find_by_qr($qr)
    {
        return $this->db->raw($this->select . " WHERE q.qr_code = ? LIMIT 1", [$qr])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Staff/doctor view of a queue. Filters: schedule_id, doctor_id, date */
    public function list_filtered(array $f)
    {
        $sql = $this->select . " WHERE q.status <> 'cancelled'";
        $args = [];
        foreach (['schedule_id' => 'a.schedule_id', 'doctor_id' => 's.doctor_id', 'date' => 's.`date`'] as $key => $col) {
            if (!empty($f[$key])) { $sql .= " AND $col = ?"; $args[] = $f[$key]; }
        }
        $sql .= " ORDER BY s.`date`, du.name, q.queue_number";
        return $this->db->raw($sql, $args)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Move the ticket to a new status and keep the appointment status in step */
    public function set_ticket_status($ticket_id, $ticket_status, $appointment_status = null)
    {
        $this->db->raw(
            "UPDATE queue_tickets SET status = ?, called_at = IF(? = 'called', NOW(), called_at) WHERE id = ?",
            [$ticket_status, $ticket_status, $ticket_id]
        );
        if ($appointment_status) {
            $this->db->raw(
                "UPDATE appointments SET status = ? WHERE id = (SELECT appointment_id FROM queue_tickets WHERE id = ?)",
                [$appointment_status, $ticket_id]
            );
        }
    }

    /** Public board: queue numbers and statuses only, no patient names */
    public function public_board($date)
    {
        return $this->db->raw(
            "SELECT du.name AS doctor_name, s.id AS schedule_id, s.start_time, s.end_time,
                    q.queue_number, q.status AS ticket_status
             FROM queue_tickets q
             JOIN appointments a ON a.id = q.appointment_id
             JOIN schedules s ON s.id = a.schedule_id
             JOIN doctors d ON d.id = s.doctor_id
             JOIN users du ON du.id = d.user_id
             WHERE s.`date` = ? AND q.status <> 'cancelled'
             ORDER BY du.name, q.queue_number", [$date]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count_by_status($date, $status)
    {
        $row = $this->db->raw(
            "SELECT COUNT(*) AS c FROM queue_tickets q JOIN appointments a ON a.id = q.appointment_id
             JOIN schedules s ON s.id = a.schedule_id WHERE s.`date` = ? AND q.status = ?", [$date, $status]
        )->fetch(PDO::FETCH_ASSOC);
        return (int) $row['c'];
    }
}
