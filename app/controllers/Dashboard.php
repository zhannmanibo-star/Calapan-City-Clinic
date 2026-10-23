<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * One small summary per role for the dashboard pages.
 */
class Dashboard extends Controller
{
    public function before_action()
    {
        $this->call->library('guard');
        $this->call->model(['Appointment_model', 'Queue_model', 'Doctor_model', 'Patient_model', 'User_model']);
    }

    // GET /api/dashboard
    public function index()
    {
        $this->guard->api()->require_method('GET');
        $payload = $this->guard->user();
        $today = date('Y-m-d');

        if ($payload['role'] === 'patient') {
            $p = $this->Patient_model->find_by_user($payload['sub']);
            $mine = $this->Appointment_model->list_filtered(['patient_id' => $p ? (int) $p['id'] : -1]);
            $upcoming = array_values(array_filter($mine, fn($a) => $a['appointment_date'] >= $today && in_array($a['status'], ['pending', 'confirmed'], true)));
            $this->guard->ok(['role' => 'patient', 'upcoming_count' => count($upcoming),
                              'next_appointment' => $upcoming ? $this->guard->with_estimate($upcoming[0]) : null]);
        }

        if ($payload['role'] === 'doctor') {
            $d = $this->Doctor_model->find_by_user($payload['sub']);
            $rows = $this->Appointment_model->list_filtered(['doctor_id' => $d ? (int) $d['id'] : -1, 'date' => $today]);
            $active = array_filter($rows, fn($a) => $a['status'] !== 'cancelled');
            $this->guard->ok(['role' => 'doctor', 'patients_today' => count($active),
                              'waiting' => count(array_filter($active, fn($a) => $a['ticket_status'] === 'waiting')),
                              'served'  => count(array_filter($active, fn($a) => $a['ticket_status'] === 'served'))]);
        }

        $data = [
            'role'               => $payload['role'],
            'doctors'            => $this->Doctor_model->count_all(),
            'appointments_today' => $this->Appointment_model->count_for_date($today),
            'waiting'            => $this->Queue_model->count_by_status($today, 'waiting'),
            'called'             => $this->Queue_model->count_by_status($today, 'called'),
            'served'             => $this->Queue_model->count_by_status($today, 'served'),
        ];
        if ($payload['role'] === 'admin') $data['users'] = count($this->User_model->all_public());
        $this->guard->ok($data);
    }
}
