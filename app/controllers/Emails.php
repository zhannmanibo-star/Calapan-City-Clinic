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
        $this->call->model(['Doctor_model', 'Email_log_model']);
    }

    // GET /api/emails   (staff/admin) - recent email log
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $this->guard->require_role(['staff', 'admin']);
        $this->guard->ok(['emails' => $this->Email_log_model->recent(100)]);
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
