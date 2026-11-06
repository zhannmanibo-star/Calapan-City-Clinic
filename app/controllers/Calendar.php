<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Google Calendar tools for the staff panel.
 */
class Calendar extends Controller
{
    public function before_action()
    {
        $this->call->library(['guard', 'calendar_sync']);
    }

    // POST /api/calendar/sync   (staff/admin)
    // Creates the calendar events that are missing, for example because Google was not set up yet.
    public function sync()
    {
        $this->guard->api()->require_method('POST');
        $this->guard->require_role(['staff', 'admin']);

        if (!$this->calendar_sync->enabled()) {
            $this->guard->fail('Google Calendar is not configured on the server.', 503);
        }
        $this->guard->ok(['message' => 'Calendar synced.', 'result' => $this->calendar_sync->sync_missing()]);
    }
}
