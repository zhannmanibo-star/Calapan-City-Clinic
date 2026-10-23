<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * One-time setup helpers. They only work when APP_ENV=development in .env,
 * so they are switched off automatically on the live (Render) site.
 *
 *   /setup/migrate  -> creates all database tables
 *   /setup/seed     -> creates demo accounts, a demo doctor and demo schedules
 */
class Setup extends Controller
{
    public function before_action()
    {
        if (getenv('APP_ENV') !== 'development') {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: text/plain; charset=utf-8');
    }

    // GET /setup/migrate
    public function migrate()
    {
        $this->call->library('migration');
        ob_start();
        $this->migration->migrate();
        $out = ob_get_clean();
        echo preg_replace('/\e\[[0-9;]*m/', '', $out);   // remove terminal colors
    }

    // GET /setup/status  - proves the database connection and shows how many rows each table has
    public function status()
    {
        $row = $this->db->raw("SELECT DATABASE() AS db, VERSION() AS version")->fetch(PDO::FETCH_ASSOC);
        echo "DATABASE CONNECTION: OK\n";
        echo "Database: {$row['db']}\nServer version: {$row['version']}\n\n";
        echo str_pad('TABLE', 22) . "ROWS\n" . str_repeat('-', 30) . "\n";
        foreach (['users', 'patients', 'doctors', 'schedules', 'appointments', 'queue_tickets',
                  'patient_records', 'email_logs', 'refresh_tokens', 'migrations'] as $t) {
            $c = $this->db->raw("SELECT COUNT(*) AS c FROM `$t`")->fetch(PDO::FETCH_ASSOC);
            echo str_pad($t, 22) . $c['c'] . "\n";
        }
    }

    // GET /setup/seed
    public function seed()
    {
        $this->call->model(['User_model', 'Doctor_model', 'Schedule_model']);

        $password  = getenv('SEED_PASSWORD') ?: '';
        $generated = false;
        if (strlen($password) < 8) {
            $password  = bin2hex(random_bytes(5)) . 'Aa1';
            $generated = true;
        }

        foreach ([['Clinic Admin', 'admin@clinic.test', 'admin'], ['Clinic Staff', 'staff@clinic.test', 'staff']] as [$name, $email, $role]) {
            if ($this->User_model->find_by_email($email)) { echo "exists:  $email\n"; continue; }
            $this->User_model->create($name, $email, $password, $role);
            echo "created: $email ($role)\n";
        }

        if ($this->User_model->find_by_email('patient@clinic.test')) {
            echo "exists:  patient@clinic.test\n";
        } else {
            $this->User_model->register_patient('Demo Patient', 'patient@clinic.test', $password,
                ['birthdate' => '2000-05-17', 'sex' => 'Female', 'address' => 'Calapan City, Oriental Mindoro', 'contact_no' => '09171234567']);
            echo "created: patient@clinic.test (patient + patient profile)\n";
        }

        $doctor_user = $this->User_model->find_by_email('doctor@clinic.test');
        if ($doctor_user) {
            $doc = $this->Doctor_model->find_by_user($doctor_user['id']);
            $doctor_id = $doc ? (int) $doc['id'] : 0;
            echo "exists:  doctor@clinic.test\n";
        } else {
            $doctor_id = $this->Doctor_model->create_with_user('Dr. Juan Dela Cruz', 'doctor@clinic.test', $password, 'General Medicine', '09181234567');
            echo "created: doctor@clinic.test (doctor + doctor profile)\n";
        }

        // Demo schedules: the next 3 days, 08:00-12:00, 20 patients each
        if ($doctor_id) {
            for ($i = 1; $i <= 3; $i++) {
                $date = date('Y-m-d', strtotime("+$i day"));
                if (!$this->Schedule_model->overlaps($doctor_id, $date, '08:00:00', '12:00:00')) {
                    $this->Schedule_model->create($doctor_id, $date, '08:00:00', '12:00:00', 20);
                    echo "created: schedule $date 08:00-12:00 (20 slots)\n";
                }
            }
        }

        echo $generated ? "\nDemo password (shown once, save it): $password\n"
                        : "\nAll demo accounts use the SEED_PASSWORD from your .env\n";
    }
}
