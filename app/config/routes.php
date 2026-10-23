<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Clinic::index');

// ---------------------------------------------------------------
// One-time setup (works only when APP_ENV=development)
// ---------------------------------------------------------------
$router->get('/setup/migrate', 'Setup::migrate');
$router->get('/setup/seed', 'Setup::seed');
$router->get('/setup/status', 'Setup::status');

// ---------------------------------------------------------------
// REST API  (JSON)  -  all URLs start with /api
// ---------------------------------------------------------------
$router->group(['prefix' => 'api'], function ($router) {

    // Authentication and profile
    $router->post('/auth/register', 'Auth::register');
    $router->post('/auth/login',    'Auth::login');
    $router->post('/auth/google',   'Auth::google');
    $router->post('/auth/refresh',  'Auth::refresh');
    $router->post('/auth/logout',   'Auth::logout');
    $router->get('/auth/me',        'Auth::me');
    $router->put('/auth/profile',   'Auth::update_profile');

    // Dashboard summary (per role)
    $router->get('/dashboard', 'Dashboard::index');

    // User accounts: staff / admin (admin only)
    $router->get('/users',          'Users::index');
    $router->post('/users',         'Users::store');
    $router->put('/users/{id}',     'Users::update');
    $router->delete('/users/{id}',  'Users::destroy');

    // Patients (staff panel)
    $router->get('/patients',         'Patients::index');
    $router->post('/patients',        'Patients::store');
    $router->get('/patients/{id}',    'Patients::show');
    $router->put('/patients/{id}',    'Patients::update');
    $router->delete('/patients/{id}', 'Patients::destroy');

    // Doctors
    $router->get('/doctors',         'Doctors::index');
    $router->post('/doctors',        'Doctors::store');
    $router->get('/doctors/{id}',    'Doctors::show');
    $router->put('/doctors/{id}',    'Doctors::update');
    $router->delete('/doctors/{id}', 'Doctors::destroy');

    // Doctor schedules
    $router->get('/schedules',          'Schedules::index');
    $router->post('/schedules',         'Schedules::store');
    $router->get('/schedules/{id}',     'Schedules::show');
    $router->put('/schedules/{id}',     'Schedules::update');
    $router->delete('/schedules/{id}',  'Schedules::destroy');

    // Appointments (booking creates the queue number + QR code)
    $router->get('/appointments',                 'Appointments::index');
    $router->post('/appointments',                'Appointments::store');
    $router->get('/appointments/{id}',            'Appointments::show');
    $router->put('/appointments/{id}',            'Appointments::update');
    $router->delete('/appointments/{id}',         'Appointments::destroy');
    $router->patch('/appointments/{id}/status',   'Appointments::update_status');
    $router->post('/appointments/{id}/cancel',    'Appointments::cancel');

    // Queue + QR check-in, public queue board
    $router->get('/queue',                  'Queue::index');
    $router->get('/queue/board',            'Queue::board');
    $router->get('/queue/ticket/{qr}',      'Queue::ticket');
    $router->post('/queue/check-in',        'Queue::check_in');
    $router->post('/queue/call-next',       'Queue::call_next');
    $router->patch('/queue/{id}/status',    'Queue::update_status');

    // Patient records
    $router->get('/records',          'Records::index');
    $router->post('/records',         'Records::store');
    $router->get('/records/{id}',     'Records::show');
    $router->put('/records/{id}',     'Records::update');
    $router->delete('/records/{id}',  'Records::destroy');

    // Emails (log + "send email to doctors")
    $router->get('/emails',          'Emails::index');
    $router->post('/emails/doctors', 'Emails::send_to_doctors');
});
