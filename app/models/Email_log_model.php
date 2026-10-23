<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * email_logs: every email the system sends (or tries to send).
 */
class Email_log_model extends Model
{
    protected $table = 'email_logs';

    public function add($user_id, $appointment_id, $type, $subject, $status)
    {
        $this->db->raw(
            "INSERT INTO email_logs (user_id, appointment_id, type, subject, status, sent_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$user_id, $appointment_id, $type, mb_substr($subject, 0, 150), $status, $status === 'sent' ? date('Y-m-d H:i:s') : null]
        );
        return (int) $this->db->last_id();
    }

    public function recent($limit = 100)
    {
        $limit = max(1, min(500, (int) $limit));
        return $this->db->raw(
            "SELECT e.id, e.user_id, u.name AS recipient_name, u.email AS recipient_email, e.appointment_id,
                    e.type, e.subject, e.status, e.sent_at
             FROM email_logs e JOIN users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT $limit"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
