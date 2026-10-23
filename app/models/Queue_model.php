<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * queue_tickets: check-in by QR code, calling patients, public queue board.
 */
class Queue_model extends Model
{
    protected $table = 'queue_tickets';
    protected $primary_key = 'id';
    protected $fillable = ['appointment_id', 'queue_number', 'qr_code', 'status', 'called_at'];
    protected $guarded = ['id'];

    // Ticket + appointment + schedule + patient + doctor
    private function base_query()
    {
        return $this->db->table('queue_tickets')
            ->select('queue_tickets.id AS ticket_id, queue_tickets.appointment_id, queue_tickets.queue_number, queue_tickets.qr_code, queue_tickets.status AS ticket_status, queue_tickets.called_at, '
                . 'appointments.status AS appointment_status, appointments.patient_id, appointments.reason, '
                . 'schedules.id AS schedule_id, schedules.doctor_id, schedules.date AS appointment_date, schedules.start_time, schedules.end_time, schedules.max_slots, '
                . 'patient_users.name AS patient_name, doctor_users.name AS doctor_name, doctors.user_id AS doctor_user_id')
            ->inner_join('appointments', 'appointments.id = queue_tickets.appointment_id')
            ->inner_join('patients', 'patients.id = appointments.patient_id')
            ->inner_join('users patient_users', 'patient_users.id = patients.user_id')
            ->inner_join('schedules', 'schedules.id = appointments.schedule_id')
            ->inner_join('doctors', 'doctors.id = schedules.doctor_id')
            ->inner_join('users doctor_users', 'doctor_users.id = doctors.user_id');
    }

    public function find_ticket($id)
    {
        return $this->base_query()->where('queue_tickets.id', $id)->row_array() ?: null;
    }

    public function find_by_qr($qr)
    {
        return $this->base_query()->where('queue_tickets.qr_code', $qr)->row_array() ?: null;
    }

    /** Staff/doctor view of a queue. Filters: schedule_id, doctor_id, date */
    public function list_filtered(array $f)
    {
        $query = $this->base_query()->where('queue_tickets.status', '<>', 'cancelled');
        foreach (['schedule_id' => 'appointments.schedule_id', 'doctor_id' => 'schedules.doctor_id', 'date' => 'schedules.date'] as $key => $column) {
            if (!empty($f[$key])) $query->where($column, $f[$key]);
        }
        $rows = $query->order_by('schedules.date')->result_array();

        usort($rows, fn($a, $b) => [$a['appointment_date'], $a['doctor_name'], (int) $a['queue_number']]
                               <=> [$b['appointment_date'], $b['doctor_name'], (int) $b['queue_number']]);
        return $rows;
    }

    /** Move the ticket to a new status and keep the appointment status in step */
    public function set_ticket_status($ticket_id, $ticket_status, $appointment_status = null)
    {
        $fields = ['status' => $ticket_status];
        if ($ticket_status === 'called') $fields['called_at'] = date('Y-m-d H:i:s');
        $this->db->table('queue_tickets')->where('id', $ticket_id)->update($fields);

        if ($appointment_status) {
            $ticket = $this->db->table('queue_tickets')->select('appointment_id')->where('id', $ticket_id)->row_array();
            $this->db->table('appointments')->where('id', $ticket['appointment_id'])->update(['status' => $appointment_status]);
        }
    }

    /** Public board: queue numbers and statuses only, no patient names */
    public function public_board($date)
    {
        $rows = $this->db->table('queue_tickets')
            ->select('doctor_users.name AS doctor_name, schedules.id AS schedule_id, schedules.start_time, schedules.end_time, queue_tickets.queue_number, queue_tickets.status AS ticket_status')
            ->inner_join('appointments', 'appointments.id = queue_tickets.appointment_id')
            ->inner_join('schedules', 'schedules.id = appointments.schedule_id')
            ->inner_join('doctors', 'doctors.id = schedules.doctor_id')
            ->inner_join('users doctor_users', 'doctor_users.id = doctors.user_id')
            ->where('schedules.date', $date)
            ->where('queue_tickets.status', '<>', 'cancelled')
            ->order_by('doctor_users.name')
            ->result_array();

        usort($rows, fn($a, $b) => [$a['doctor_name'], (int) $a['queue_number']] <=> [$b['doctor_name'], (int) $b['queue_number']]);
        return $rows;
    }

    public function count_by_status($date, $status)
    {
        $rows = $this->db->table('queue_tickets')
            ->select('queue_tickets.id')
            ->inner_join('appointments', 'appointments.id = queue_tickets.appointment_id')
            ->inner_join('schedules', 'schedules.id = appointments.schedule_id')
            ->where('schedules.date', $date)
            ->where('queue_tickets.status', $status)
            ->result_array();
        return count($rows);
    }
}
