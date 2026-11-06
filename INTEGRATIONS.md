# The 5 integrations: how to set them up

| # | Integration | Backend (LavaLust) | Frontend (React) | Keys / accounts |
|---|---|---|---|---|
| 1 | Google login | `POST /api/auth/google` | "Sign in with Google" button | Google OAuth Client ID |
| 2 | Email (Brevo) | confirmations, cancellations, reminders, doctor emails | nothing | Brevo API key + verified sender |
| 3 | QR code | every booking gets a `qr_code`; `POST /api/queue/check-in` | draw the QR, scan it | none |
| 4 | Google Maps | nothing | embedded map | Maps Embed API key |
| 5 | Google Calendar | clinic hours + queue events on one shared calendar | optional | service account key + Calendar ID |

**Check everything from the backend:** open `http://localhost:3000/setup/integrations` (development only). It tests your
real keys and tells you what is wrong. Add `?send_test_to=you@gmail.com` to also send a test email.

## 1. Google Cloud (one project for Login, Maps and Calendar)
1. https://console.cloud.google.com -> create a project.
2. **Login:** APIs & Services -> OAuth consent screen (External). While it is in Testing mode only the Google accounts you
   add as *test users* can sign in, so add your groupmates and your sir. Then Credentials -> Create credentials ->
   OAuth client ID -> **Web application**. Authorized JavaScript origins: `http://localhost:5173` and your live frontend
   address (for example `https://clinic-frontend-kohl-two.vercel.app`). No redirect URI is needed.
3. Copy the **Client ID**. It goes in two places (it is public, not a secret):
   - backend `.env`: `GOOGLE_CLIENT_ID=...`
   - frontend `.env`: `VITE_GOOGLE_CLIENT_ID=...`
4. **Maps:** enable *Maps Embed API*, create an API key and restrict it to your website addresses. Google says the Embed
   API has no usage charge, but the project must have a billing account attached.
   Frontend `.env`: `VITE_GOOGLE_MAPS_API_KEY=...`
5. **Calendar:** enable *Google Calendar API*. Then IAM & Admin -> Service accounts -> create one -> Keys -> Add key -> JSON.
   Save the file as `google-key.json` in the BACKEND folder (it is a secret: add `google-key.json` to `.gitignore`).
6. In Google Calendar create a calendar called "Calapan Clinic". Settings -> Share with specific people -> add the service
   account's email (`...@...iam.gserviceaccount.com`) with permission **Make changes to events**. Settings -> Integrate
   calendar -> copy the **Calendar ID**.
7. Backend `.env`:
   ```
   GOOGLE_SERVICE_ACCOUNT_JSON=google-key.json
   GOOGLE_CALENDAR_ID=paste-the-calendar-id-here
   ```
   On Render, paste the base64 text of the key file instead: `base64 -w0 google-key.json` (Mac/Linux) or
   `[Convert]::ToBase64String([IO.File]::ReadAllBytes("google-key.json"))` (PowerShell).
8. Open `/setup/integrations`. Then in the staff panel (or with the API tester) call `POST /api/calendar/sync` once to
   put the existing schedules on the calendar. After that it happens automatically.

Note: a service account cannot invite attendees (that needs paid Google Workspace setup), so events appear on the clinic
calendar but patients do not get invitations. Only the queue number and name go on an event, never the reason for the visit.

## 2. Brevo (email)
1. https://www.brevo.com -> sign up (the free plan allows 300 emails a day).
2. Senders -> add your email address -> click the verification link Brevo emails you.
3. SMTP & API -> API keys -> create a key.
4. Backend `.env`:
   ```
   BREVO_API_KEY=xkeysib-...
   MAIL_FROM=the-address-you-verified@gmail.com
   MAIL_FROM_NAME=Calapan City Clinic
   ```
5. `/setup/integrations?send_test_to=your@email` should say SENT. Then book an appointment with a real patient email and
   look at `GET /api/emails`: `sent` = delivered to Brevo, `failed` = key or sender problem, `queued` = no key loaded.
6. Reminders: `POST /api/emails/reminders` emails everyone with an appointment tomorrow. Call it once a day (a free
   scheduler such as cron-job.org can do that) or press a button in the staff panel.

**Windows:** if Google or Brevo calls fail with "SSL certificate problem", download https://curl.se/ca/cacert.pem into the
backend folder and put `CURL_CA_BUNDLE=cacert.pem` in `.env`.

## 3. CORS (needed once the frontend is deployed)
Backend `.env`: `CORS_ORIGINS=http://localhost:5173,https://clinic-frontend-kohl-two.vercel.app` (exact addresses, no
trailing slash). On your own computer the frontend's Vite proxy forwards `/api`, so CORS is not needed there.

## Frontend snippets (React + Vite)

**Google login button** (`src/GoogleLoginButton.jsx`). No extra package is needed:
```jsx
import { useEffect, useRef } from 'react';

export default function GoogleLoginButton({ onSuccess, onError }) {
  const box = useRef(null);
  useEffect(() => {
    const clientId = import.meta.env.VITE_GOOGLE_CLIENT_ID;
    if (!clientId) return;
    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.onload = () => {
      window.google.accounts.id.initialize({
        client_id: clientId,
        callback: async ({ credential }) => {
          try {
            const res = await fetch('/api/auth/google', {          // same base URL your api helper uses
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ credential }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Google sign-in failed');
            onSuccess(data);   // data.user and data.tokens, same shape as /auth/login
          } catch (e) { onError?.(e); }
        },
      });
      window.google.accounts.id.renderButton(box.current, { theme: 'outline', size: 'large', text: 'signin_with' });
    };
    document.body.appendChild(script);
    return () => script.remove();
  }, []);
  return <div ref={box} />;
}
```
`onSuccess` should do exactly what the normal login does with `data.tokens` and `data.user`.

**Clinic location map** (Maps Embed API; change the `q=` text to the clinic's real address):
```jsx
export default function ClinicMap() {
  const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;
  const q = encodeURIComponent('Calapan City Health Office, Calapan, Oriental Mindoro');
  return (
    <iframe title="Clinic location" width="100%" height="360" style={{ border: 0 }} loading="lazy"
      referrerPolicy="no-referrer-when-downgrade"
      src={`https://www.google.com/maps/embed/v1/place?key=${key}&q=${q}`} />
  );
}
```

**QR ticket** (`npm i qrcode.react`): `<QRCodeSVG value={appointment.qr_code} size={180} />`.
**QR scanner for staff** (`npm i html5-qrcode`): scan, then `POST /api/queue/check-in` with `{ qr_code: scannedText }`.

**Calendar:** nothing is required on the frontend. To let the clinic see it, open the shared "Calapan Clinic" calendar in Google Calendar.
