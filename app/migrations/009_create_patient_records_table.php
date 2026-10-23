<?php

/**
 * patient_records (ERD): the doctor's diagnosis and notes for a visit.
 */
class Create_patient_records_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS patient_records (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            appointment_id INT UNSIGNED NOT NULL,
            doctor_id INT UNSIGNED NOT NULL,
            diagnosis TEXT NULL,
            notes TEXT NULL,
            visit_date DATE NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY record_appointment_unique (appointment_id),
            KEY record_doctor (doctor_id),
            CONSTRAINT fk_record_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id),
            CONSTRAINT fk_record_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw("DROP TABLE IF EXISTS patient_records");
    }
}
