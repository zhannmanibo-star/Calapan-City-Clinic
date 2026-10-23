<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Clinic extends Controller
{
    // GET /
    public function index()
    {
        $this->call->library('guard');
        $this->guard->ok([
            'name'   => 'Calapan City Clinic API',
            'status' => 'running',
            'api'    => '/api',
        ]);
    }
}