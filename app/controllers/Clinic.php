<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Clinic extends Controller
{
    public function index()
    {
        $this->call->view('clinic/home');
    }

    public function about()
    {
        $this->call->view('clinic/about');
    }
}