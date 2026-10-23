<?php

/**
 * queue_tickets (ERD): the queue number and QR code issued for an appointment.
 * status: issued -> waiting (checked in) -> called -> served, or skipped / cancelled.
 */
class Create_queue_tickets_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS queue_tickets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            appointment_id INT UNSIGNED NOT NULL,
            queue_number INT UNSIGNED NOT NULL,
            qr_code VARCHAR(255) NOT NULL,
            status ENUM('issued','waiting','called','served','skipped','cancelled') NOT NULL DEFAULT 'issued',
            called_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_appointment_unique (appointment_id),
            UNIQUE KEY ticket_qr_unique (qr_code),
            CONSTRAINT fk_ticket_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw("DROP TABLE IF EXISTS queue_tickets");
    }
}
