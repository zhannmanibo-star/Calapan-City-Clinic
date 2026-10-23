<?php

/**
 * schedules (ERD): a doctor's clinic hours on a date, with a patient limit (max_slots).
 * status: open = accepting bookings, full = max_slots reached, closed = cancelled by staff.
 */
class Create_schedules_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS schedules (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            doctor_id INT UNSIGNED NOT NULL,
            `date` DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            max_slots INT UNSIGNED NOT NULL DEFAULT 20,
            status ENUM('open','full','closed') NOT NULL DEFAULT 'open',
            gcal_event_id VARCHAR(100) NULL,
            PRIMARY KEY (id),
            KEY schedule_lookup (doctor_id, `date`),
            CONSTRAINT fk_schedules_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw("DROP TABLE IF EXISTS schedules");
    }
}
