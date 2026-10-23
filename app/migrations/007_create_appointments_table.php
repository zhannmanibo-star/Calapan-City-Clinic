<?php

/**
 * appointments (ERD): a patient's booking on a schedule.
 */
class Create_appointments_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS appointments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id INT UNSIGNED NOT NULL,
            schedule_id INT UNSIGNED NOT NULL,
            status ENUM('pending','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
            reason TEXT NULL,
            gcal_event_id VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY appt_patient (patient_id),
            KEY appt_schedule (schedule_id),
            CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
            CONSTRAINT fk_appt_schedule FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw("DROP TABLE IF EXISTS appointments");
    }
}
