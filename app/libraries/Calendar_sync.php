<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Calendar_sync - decides what goes on the clinic's Google Calendar.
 *   - every schedule  -> one "Clinic hours" event            (schedules.gcal_event_id)
 *   - every appointment -> one "Queue #N" event               (appointments.gcal_event_id)
 * Controllers call one method here after saving to the database. If Google is not set up
 * or fails, nothing breaks: the id is just left empty and /api/calendar/sync can fill it in later.
 */
class Calendar_sync
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->library(['google_calendar', 'guard']);
        $this->lava->call->model(['Schedule_model', 'Appointment_model']);
    }

    public function enabled()
    {
        return $this->lava->google_calendar->enabled();
    }

    /** Create / update the event of a schedule. A closed schedule has no event. */
    public function schedule_saved($schedule_id)
    {
        if (!$this->enabled()) return;
        $s = $this->lava->Schedule_model->find($schedule_id);
        if (!$s) return;

        $calendar = $this->lava->google_calendar;
        if ($s['status'] === 'closed') {
            if ($s['gcal_event_id'] && $calendar->delete_event($s['gcal_event_id'])) {
                $this->lava->Schedule_model->set_event_id($s['id'], null);
            }
            return;
        }

        $summary     = 'Clinic hours: ' . $s['doctor_name'];
        $description = $s['specialization'] . ' - up to ' . $s['max_slots'] . ' patients';

        if ($s['gcal_event_id']) {
            if ($calendar->update_event($s['gcal_event_id'], $summary, $description, $s['date'], $s['start_time'], $s['end_time'])) return;
            if (!in_array($calendar->last_code, [404, 410], true)) return;   // a real error: keep the id and try again later
        }
        $event_id = $calendar->create_event($summary, $description, $s['date'], $s['start_time'], $s['end_time']);
        if ($event_id) $this->lava->Schedule_model->set_event_id($s['id'], $event_id);
    }

    /** Call BEFORE the schedule row is deleted */
    public function schedule_removed(array $schedule)
    {
        if ($this->enabled() && $schedule['gcal_event_id']) {
            $this->lava->google_calendar->delete_event($schedule['gcal_event_id']);
        }
    }

    /** Create the event of a new appointment, at the patient's estimated time */
    public function appointment_booked($appointment_id)
    {
        if (!$this->enabled()) return;
        $a = $this->lava->Appointment_model->find($appointment_id);
        if (!$a || $a['gcal_event_id'] || $a['status'] === 'cancelled') return;

        $a     = $this->lava->guard->with_estimate($a);
        $start = $a['estimated_time'] . ':00';
        $step  = max(10, intdiv(max(0, strtotime('1970-01-01 ' . $a['end_time']) - strtotime('1970-01-01 ' . $a['start_time'])), max(1, (int) $a['max_slots'])) / 60);
        $end   = date('H:i:s', strtotime('1970-01-01 ' . $start) + ((int) $step) * 60);

        // No reason or health details go on the calendar, only who and when
        $event_id = $this->lava->google_calendar->create_event(
            'Queue #' . $a['queue_number'] . ' - ' . $a['patient_name'],
            'Doctor: ' . $a['doctor_name'],
            $a['appointment_date'], $start, $end
        );
        if ($event_id) $this->lava->Appointment_model->set_event_id($a['id'], $event_id);
    }

    /** Remove the event of a cancelled / deleted appointment ($appointment is the row as loaded before the change) */
    public function appointment_removed(array $appointment)
    {
        if (!$this->enabled() || !$appointment['gcal_event_id']) return;
        if ($this->lava->google_calendar->delete_event($appointment['gcal_event_id'])) {
            $this->lava->Appointment_model->set_event_id($appointment['id'], null);
        }
    }

    /** Create the events that are missing (for example because Google was not set up yet) */
    public function sync_missing()
    {
        $result = ['schedules' => 0, 'appointments' => 0, 'still_missing' => 0];
        $today  = date('Y-m-d');

        foreach ($this->lava->Schedule_model->list_for() as $s) {
            if ($s['status'] === 'closed' || $s['gcal_event_id']) continue;
            $this->schedule_saved($s['id']);
            $after = $this->lava->Schedule_model->find($s['id']);
            $after && $after['gcal_event_id'] ? $result['schedules']++ : $result['still_missing']++;
        }
        foreach ($this->lava->Appointment_model->list_filtered([]) as $a) {
            if ($a['status'] === 'cancelled' || $a['gcal_event_id'] || $a['appointment_date'] < $today) continue;
            $this->appointment_booked($a['id']);
            $after = $this->lava->Appointment_model->find($a['id']);
            $after && $after['gcal_event_id'] ? $result['appointments']++ : $result['still_missing']++;
        }
        return $result;
    }
}
