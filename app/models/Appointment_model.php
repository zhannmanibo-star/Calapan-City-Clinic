<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * appointments (+ the queue ticket issued for each one).
 */
class Appointment_model extends Model
{
    protected $table = 'appointments';
    protected $primary_key = 'id';
    protected $fillable = ['patient_id', 'schedule_id', 'status', 'reason', 'gcal_event_id'];
    protected $guarded = ['id'];

    // Appointment + schedule + patient + doctor + queue ticket in one query
    private function base_query()
    {
        return $this->db->table('appointments')
            ->select('appointments.id, appointments.patient_id, appointments.schedule_id, appointments.status, appointments.reason, appointments.gcal_event_id, appointments.created_at, '
                . 'schedules.doctor_id, schedules.date AS appointment_date, schedules.start_time, schedules.end_time, schedules.max_slots, '
                . 'patient_users.name AS patient_name, patient_users.email AS patient_email, patients.user_id AS patient_user_id, '
                . 'doctor_users.name AS doctor_name, doctors.specialization, doctors.user_id AS doctor_user_id, '
                . 'queue_tickets.id AS ticket_id, queue_tickets.queue_number, queue_tickets.qr_code, queue_tickets.status AS ticket_status, queue_tickets.called_at')
            ->inner_join('patients', 'patients.id = appointments.patient_id')
            ->inner_join('users patient_users', 'patient_users.id = patients.user_id')
            ->inner_join('schedules', 'schedules.id = appointments.schedule_id')
            ->inner_join('doctors', 'doctors.id = schedules.doctor_id')
            ->inner_join('users doctor_users', 'doctor_users.id = doctors.user_id')
            ->left_join('queue_tickets', 'queue_tickets.appointment_id = appointments.id');
    }

    public function find($id)
    {
        return $this->base_query()->where('appointments.id', $id)->row_array() ?: null;
    }

    /** $f may contain: patient_id, doctor_id, schedule_id, date, status */
    public function list_filtered(array $f)
    {
        $query = $this->base_query();
        foreach (['patient_id' => 'appointments.patient_id', 'doctor_id' => 'schedules.doctor_id',
                  'schedule_id' => 'appointments.schedule_id', 'date' => 'schedules.date',
                  'status' => 'appointments.status'] as $key => $column) {
            if (!empty($f[$key])) $query->where($column, $f[$key]);
        }
        $rows = $query->order_by('schedules.date')->result_array();

        usort($rows, fn($a, $b) => [$a['appointment_date'], $a['start_time'], (int) $a['queue_number']]
                               <=> [$b['appointment_date'], $b['start_time'], (int) $b['queue_number']]);
        return $rows;
    }

    public function count_for_date($date)
    {
        $schedule_ids = array_column($this->db->table('schedules')->select('id')->where('date', $date)->result_array(), 'id');
        if (!$schedule_ids) return 0;
        return (int) $this->db->table('appointments')
            ->in('schedule_id', $schedule_ids)
            ->where('status', '<>', 'cancelled')
            ->count();
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
            // The Query Builder has no row-lock method, so this one statement is raw SQL
            $schedule = $this->db->raw('SELECT id, status, max_slots FROM schedules WHERE id = ? FOR UPDATE', [$schedule_id])
                                 ->fetch(PDO::FETCH_ASSOC);
            if (!$schedule || $schedule['status'] === 'closed') {
                $this->db->roll_back();
                return ['error' => 'This schedule is not available.', 'code' => 409];
            }

            $booked = $this->db->table('appointments')
                ->where('schedule_id', $schedule_id)->where('status', '<>', 'cancelled')->count();
            if ($booked >= (int) $schedule['max_slots']) {
                $this->db->roll_back();
                return ['error' => 'This schedule is already full. Please choose another date.', 'code' => 409];
            }

            $duplicate = $this->db->table('appointments')
                ->where('patient_id', $patient_id)->where('schedule_id', $schedule_id)->where('status', '<>', 'cancelled')
                ->row_array();
            if ($duplicate) {
                $this->db->roll_back();
                return ['error' => 'You already have an appointment on this schedule.', 'code' => 409];
            }

            // Queue numbers never repeat within a schedule, even after cancellations
            $last = $this->db->table('queue_tickets')
                ->select_max('queue_tickets.queue_number', 'last_number')
                ->inner_join('appointments', 'appointments.id = queue_tickets.appointment_id')
                ->where('appointments.schedule_id', $schedule_id)
                ->row_array();

            $this->db->table('appointments')->insert([
                'patient_id' => $patient_id, 'schedule_id' => $schedule_id, 'reason' => $reason,
            ]);
            $id = (int) $this->db->last_id();

            $this->db->table('queue_tickets')->insert([
                'appointment_id' => $id,
                'queue_number'   => (int) ($last['last_number'] ?? 0) + 1,
                'qr_code'        => bin2hex(random_bytes(16)),
            ]);

            $this->db->commit();
            $this->sync_schedule($schedule_id);
            return ['id' => $id];
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Change the appointment status. Cancelling also cancels the ticket and frees the place. */
    public function set_status($id, $status)
    {
        $appointment = $this->db->table('appointments')->where('id', $id)->row_array();
        $this->db->table('appointments')->where('id', $id)->update(['status' => $status]);
        if ($status === 'cancelled') {
            $this->db->table('queue_tickets')->where('appointment_id', $id)->update(['status' => 'cancelled']);
        }
        $this->sync_schedule($appointment['schedule_id']);
    }

    public function update_reason($id, $reason)
    {
        $this->db->table('appointments')->where('id', $id)->update(['reason' => $reason]);
    }

    public function has_record($id)
    {
        return $this->db->table('patient_records')->where('appointment_id', $id)->count() > 0;
    }

    /** Delete an appointment with its queue ticket. Email log entries are kept, unlinked. */
    public function remove($id)
    {
        $appointment = $this->db->table('appointments')->where('id', $id)->row_array();
        $this->db->transaction();
        try {
            $this->db->table('queue_tickets')->where('appointment_id', $id)->delete();
            $this->db->table('email_logs')->where('appointment_id', $id)->update(['appointment_id' => null]);
            $this->db->table('appointments')->where('id', $id)->delete();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
        $this->sync_schedule($appointment['schedule_id']);
    }

    // Keeps the schedule's open / full status in step with its bookings
    private function sync_schedule($schedule_id)
    {
        $schedule = $this->db->table('schedules')->where('id', $schedule_id)->row_array();
        if (!$schedule || $schedule['status'] === 'closed') return;

        $booked = $this->db->table('appointments')
            ->where('schedule_id', $schedule_id)->where('status', '<>', 'cancelled')->count();
        $this->db->table('schedules')->where('id', $schedule_id)
            ->update(['status' => $booked >= (int) $schedule['max_slots'] ? 'full' : 'open']);
    }
}
