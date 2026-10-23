<?php

/**
 * Reshapes the starter users table to match the approved ERD:
 * id, google_id, name, email, password, role, created_at
 * (roles: admin, staff, doctor, patient)
 */
class Reshape_users_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $this->_lava->db->raw("ALTER TABLE users
            DROP COLUMN username,
            DROP COLUMN is_active,
            DROP COLUMN updated_at,
            MODIFY email VARCHAR(100) NOT NULL,
            MODIFY password VARCHAR(255) NULL,
            MODIFY role ENUM('admin','staff','doctor','patient') NOT NULL DEFAULT 'patient',
            ADD COLUMN google_id VARCHAR(50) NULL AFTER id,
            ADD COLUMN name VARCHAR(100) NOT NULL AFTER google_id,
            ADD UNIQUE KEY google_id_unique (google_id)");
    }

    public function down()
    {
        $this->_lava->db->raw("ALTER TABLE users
            DROP INDEX google_id_unique,
            DROP COLUMN name,
            DROP COLUMN google_id,
            ADD COLUMN username VARCHAR(100) NOT NULL,
            ADD COLUMN is_active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            ADD COLUMN updated_at DATETIME NULL,
            MODIFY role ENUM('admin','moderator','user') NOT NULL DEFAULT 'user'");
    }
}
