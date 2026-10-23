<?php

/**
 * doctors (ERD): a doctor profile linked to one user account.
 */
class Create_doctors_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS doctors (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            specialization VARCHAR(100) NOT NULL,
            contact_no VARCHAR(20) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY doctors_user_unique (user_id),
            CONSTRAINT fk_doctors_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw("DROP TABLE IF EXISTS doctors");
    }
}
