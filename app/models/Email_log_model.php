<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * email_logs: every email the system sends (or tries to send).
 * It is a record of what happened, so it is only added to and read, never edited.
 */
class Email_log_model extends Model
{
    protected $table = 'email_logs';
    protected $primary_key = 'id';
    protected $fillable = ['user_id', 'appointment_id', 'type', 'subject', 'status', 'sent_at'];
    protected $guarded = ['id'];

    public function add($user_id, $appointment_id, $type, $subject, $status)
    {
        $this->db->table('email_logs')->insert([
            'user_id'        => $user_id,
            'appointment_id' => $appointment_id,
            'type'           => $type,
            'subject'        => mb_substr($subject, 0, 150),
            'status'         => $status,
            'sent_at'        => $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ]);
        return (int) $this->db->last_id();
    }

    public function recent($limit = 100)
    {
        return $this->db->table('email_logs')
            ->select('email_logs.id, email_logs.user_id, users.name AS recipient_name, users.email AS recipient_email, email_logs.appointment_id, email_logs.type, email_logs.subject, email_logs.status, email_logs.sent_at')
            ->inner_join('users', 'users.id = email_logs.user_id')
            ->order_by('email_logs.id', 'DESC')
            ->limit(max(1, min(500, (int) $limit)))
            ->result_array();
    }
}
