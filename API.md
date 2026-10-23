# Calapan Clinic API (for the frontend)

Base URL: `{your-server}/api`   (example: `http://localhost:8080/api`)
All requests and responses are JSON.

## Login token
Log in, then send the token on every protected request:

    Authorization: Bearer <access_token>

`POST /auth/login` returns `{ message, user, tokens: { access_token, refresh_token, expires_in } }`.
When the access token expires, call `POST /auth/refresh` with the refresh token.

## Errors
Every error looks like `{ "error": "message to show the user", "status": 422 }`.
`401` not logged in · `403` wrong role · `404` not found · `409` conflict (duplicate / full) · `422` invalid input · `429` too many tries.

## Roles
`patient`, `doctor`, `staff`, `admin`. Column "Who" shows who may call the endpoint (login required unless it says Public).

## Auth and profile
| Method | URL | Who | Body |
|---|---|---|---|
| POST | /auth/register | Public | `name, email, password, birthdate?, sex?(Male/Female/Other), address?, contact_no?` |
| POST | /auth/login | Public | `email, password` |
| POST | /auth/google | Public | `credential` (Google ID token) - needs GOOGLE_CLIENT_ID on the server |
| POST | /auth/refresh | Public | `refresh_token` |
| POST | /auth/logout | Public | `refresh_token` |
| GET | /auth/me | any | - returns `user` and `patient_profile` |
| PUT | /auth/profile | patient | `name?, birthdate?, sex?, address?, contact_no?` |
| GET | /dashboard | any | - different numbers per role |

## Doctors and schedules
| Method | URL | Who | Body / query |
|---|---|---|---|
| GET | /doctors | any | - |
| GET | /doctors/{id} | any | - |
| POST | /doctors | staff, admin | `name, email, password, specialization, contact_no?` |
| PUT | /doctors/{id} | staff, admin | same fields (all optional) |
| DELETE | /doctors/{id} | staff, admin | refused (409) if the doctor has appointments or records |
| GET | /schedules | any | `?doctor_id=&date=YYYY-MM-DD` (default: today and later). Each item has `booked` and `slots_left` |
| GET | /schedules/{id} | any | - |
| POST | /schedules | staff, admin | `doctor_id, date, start_time(HH:MM), end_time, max_slots?` |
| PUT | /schedules/{id} | staff, admin | `start_time?, end_time?, max_slots?, status?(open/closed)` |
| DELETE | /schedules/{id} | staff, admin | refused (409) while patients are booked |

Schedule `status`: `open` (accepting), `full` (max_slots reached), `closed` (cancelled by staff).

## Appointments (booking creates the queue number and QR code)
| Method | URL | Who | Body / query |
|---|---|---|---|
| POST | /appointments | patient | `schedule_id, reason?` -> returns the appointment with `queue_number`, `qr_code`, `estimated_time` |
| GET | /appointments | any | `?date=&status=&doctor_id=&schedule_id=` - patients see only their own, doctors only their patients |
| GET | /appointments/{id} | owner, doctor, staff, admin | - |
| POST | /appointments/{id}/cancel | patient (own) | - |
| PATCH | /appointments/{id}/status | staff, admin | `status` = `confirmed` or `cancelled` |

Appointment `status`: pending, confirmed, completed, cancelled, no_show.
`ticket_status`: issued, waiting, called, served, skipped, cancelled.

**QR code:** `qr_code` is a 32-character text. The frontend turns it into a QR image (for example with the `qrcode.react` package). Staff scan it and send the text to `/queue/check-in`.

## Queue
| Method | URL | Who | Body / query |
|---|---|---|---|
| POST | /queue/check-in | staff, admin | `qr_code` - patient arrived (only works on the appointment date) |
| GET | /queue | staff, doctor, admin | `?date=&schedule_id=&doctor_id=` (default: today) |
| GET | /queue/ticket/{qr_code} | staff, doctor, admin | look up a scanned code |
| POST | /queue/call-next | staff, doctor, admin | `schedule_id` - calls the waiting patient with the lowest number |
| PATCH | /queue/{ticket_id}/status | staff, doctor, admin | `status` = `called`, `served` or `skipped` |
| GET | /queue/board | **Public** | `?date=` - `now_serving`, `waiting` and queue numbers per doctor, no patient names |

Ticket flow: `issued` -> (check-in) `waiting` -> `called` -> `served`. `skipped` is allowed before serving.
Appointment status follows automatically: served = completed, skipped = no_show.

## Patient records
| Method | URL | Who | Body / query |
|---|---|---|---|
| GET | /records | any | patients get their own; others need `?patient_id=` |
| GET | /records/{id} | owner patient, doctor, staff, admin | - |
| POST | /records | doctor (own patient), staff, admin | `appointment_id, diagnosis?, notes?, visit_date?` (one record per appointment) |
| PUT | /records/{id} | the doctor who wrote it, staff, admin | `diagnosis?, notes?` |
| DELETE | /records/{id} | the doctor who wrote it, admin | - |

## Staff / admin panel
| Method | URL | Who | Body |
|---|---|---|---|
| GET | /users | admin | - |
| POST | /users | admin | `name, email, password, role` (staff or admin) |
| PUT | /users/{id} | admin | `name?, email?` |
| DELETE | /users/{id} | admin | not your own account |
| POST | /emails/doctors | staff, admin | `subject, message, doctor_id?` (no doctor_id = all doctors) |
| GET | /emails | staff, admin | the email log |

## Demo accounts (created by /setup/seed, password = SEED_PASSWORD in `.env`)
`admin@clinic.test`, `staff@clinic.test`, `doctor@clinic.test`, `patient@clinic.test`
