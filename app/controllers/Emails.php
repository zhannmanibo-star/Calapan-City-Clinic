<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Staff panel -> "Send Email to Doctors", and the email log.
 */
class Emails extends Controller
{
    public function before_action()
    {
        $this->call->library(['guard', 'mailer']);
        $this->call->model(['Doctor_model', 'Email_log_model', 'Appointment_model']);
    }

    // GET /api/emails   (staff/admin) - recent email log
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $this->guard->require_role(['staff', 'admin']);
        $this->guard->ok(['emails' => $this->Email_log_model->recent(100)]);
    }

    // POST /api/emails/reminders   (staff/admin)   { date? }  (default: tomorrow)
    // Emails every patient who still has an appointment that day. Patients already reminded are skipped,
    // so it is safe to call this again (or from a daily scheduler such as cron-job.org).
    public function reminders()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['staff', 'admin']);

        $in   = $this->guard->body();
        $date = (string) ($in['date'] ?? date('Y-m-d', strtotime('+1 day')));
        if (!$this->guard->valid_date($date)) $this->guard->fail('Date must look like 2026-11-05.', 422);

        $result = ['sent' => 0, 'queued' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->Appointment_model->list_filtered(['date' => $date]) as $a) {
            $active = in_array($a['status'], ['pending', 'confirmed'], true) && in_array($a['ticket_status'], ['issued', 'waiting'], true);
            if (!$active || $this->Email_log_model->already_logged($a['id'], 'reminder')) { $result['skipped']++; continue; }

            $a = $this->guard->with_estimate($a);
            $status = $this->mailer->send($a['patient_user_id'], $a['id'], 'reminder', $a['patient_email'], 'Reminder: your clinic appointment',
                "Hello {$a['patient_name']},\n\nThis is a reminder of your appointment with {$a['doctor_name']} on {$a['appointment_date']}.\n"
                . "Queue number: {$a['queue_number']}\nEstimated time: {$a['estimated_time']}\n\nPlease bring your QR ticket.\n\n- Calapan City Health and Sanitation Department");
            $result[$status]++;
        }
        $this->guard->ok(['message' => 'Reminders processed.', 'date' => $date, 'result' => $result]);
    }

    // POST /api/emails/doctors   (staff/admin)   { subject, message, doctor_id? }  (no doctor_id = all doctors)
    public function send_to_doctors()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['staff', 'admin']);

        $in      = $this->guard->body();
        $subject = trim($in['subject'] ?? '');
        $message = trim($in['message'] ?? '');
        if ($subject === '' || mb_strlen($subject) > 150)    $this->guard->fail('Subject is required (150 characters max).', 422);
        if ($message === '' || mb_strlen($message) > 2000)   $this->guard->fail('Message is required (2000 characters max).', 422);

        if (!empty($in['doctor_id'])) {
            $d = $this->Doctor_model->find((int) $in['doctor_id']);
            if (!$d) $this->guard->fail('Doctor not found.', 404);
            $doctors = [$d];
        } else {
            $doctors = $this->Doctor_model->all_doctors();
        }
        if (!$doctors) $this->guard->fail('There are no doctors to email.', 404);

        $result = ['sent' => 0, 'queued' => 0, 'failed' => 0];
        foreach ($doctors as $d) {
            $status = $this->mailer->send($d['user_id'], null, 'doctor_notice', $d['email'], $subject, $message);
            $result[$status]++;
        }
        $this->guard->ok(['message' => 'Email request processed.', 'result' => $result], 201);
    }
}
