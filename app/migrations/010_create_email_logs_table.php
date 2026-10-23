<?php

/**
 * email_logs (ERD): a record of every email the system sends (or tries to send).
 */
class Create_email_logs_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS email_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            appointment_id INT UNSIGNED NULL,
            type ENUM('booking_confirmation','reminder','cancellation','doctor_notice','other') NOT NULL DEFAULT 'other',
            subject VARCHAR(150) NOT NULL,
            status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY email_user (user_id),
            KEY email_appt (appointment_id),
            CONSTRAINT fk_email_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_email_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw("DROP TABLE IF EXISTS email_logs");
    }
}
