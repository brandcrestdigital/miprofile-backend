<?php
namespace App\Http\Controllers;

use App\Mail\MeetingCancelledMail;
use App\Mail\MeetingConfirmationMail;
use App\Models\Meeting;
use App\Models\ProfileAnalytics;
use App\Models\StaffCard;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\UserGoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class MeetingController extends Controller
{
    /**
     * Store a newly created meeting (public route – no auth).
     */
    public function store_w(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'      => 'required|exists:users,id',
            'name'         => 'required|string|max:255',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|digits:10',
            'company_name' => 'nullable|string|max:255',
            'title'        => 'required|string|max:255',
            'meeting_type' => 'required|in:online,offline',
            'location'     => 'required_if:meeting_type,offline|nullable|string|max:255',
            'description'  => 'nullable|string',
            'date'         => 'required|date|after_or_equal:today',
            'time'         => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $meeting = Meeting::create([
            'user_id'         => $request->user_id,
            'requester_name'  => $request->name,
            'requester_email' => $request->email,
            'phone'           => $request->phone,
            'company_name'    => $request->company_name,
            'title'           => $request->title,
            'description'     => $request->description,
            'meeting_date'    => $request->date,
            'meeting_time'    => $request->time,
            'status'          => 'pending',
        ]);

        $user = User::find($request->user_id);

        if ($user) {

            try {

                // if ($user->email) {
                //     Mail::to($user->email)
                //         ->send(
                //             new MeetingRequestReceivedMail(
                //                 $meeting,
                //                 $user
                //             )
                //         );
                // }

                $message = "📅 New Meeting Request

Hi {$user->name},

You have received a new meeting request through Mi Profile.

Requester Name: {$meeting->requester_name}
Company: {$meeting->company_name}
Email: {$meeting->requester_email}

Meeting Title:
{$meeting->title}

Date:
{$meeting->meeting_date}

Time:
{$meeting->meeting_time}

Please log in to your Mi Profile dashboard to review and confirm the meeting request.

Team Mi Profile
Connect Beyond Contacts.";

                app(\App\Services\Messenger360Service::class)
                    ->send($user->phone, $message);

            } catch (\Exception $e) {

                \Log::error('Meeting Notification Failed', [
                    'meeting_id' => $meeting->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Meeting request sent successfully',
            'data'    => $meeting,
        ], 201);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'      => 'required|exists:users,id',
            'name'         => 'required|string|max:255',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|digits:10',
            'company_name' => 'nullable|string|max:255',
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'meeting_type' => 'required|in:online,offline',
            'location'     => 'required_if:meeting_type,offline|nullable|string|max:255',
            'date'         => 'required|date|after_or_equal:today',
            'time'         => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $meeting = Meeting::create([
            'user_id'         => $request->user_id,
            'requester_name'  => $request->name,
            'requester_email' => $request->email,
            'phone'           => $request->phone,
            'company_name'    => $request->company_name,
            'title'           => $request->title,
            'description'     => $request->description,
            'meeting_type'    => $request->meeting_type,
            'location'        => $request->meeting_type == 'offline'
                ? $request->location
                : null,
            'meeting_date'    => $request->date,
            'meeting_time'    => $request->time,
            'status'          => 'pending',
        ]);

        // Count Meeting Request
        $card = StaffCard::where('user_id', $request->user_id)->first();

        if ($card) {

            $exists = ProfileAnalytics::where('card_id', $card->id)
                ->where('action', 'meeting')
                ->where('ip_address', $request->ip())
            // ->whereDate('created_at', today())
                ->exists();

            if (! $exists) {
                ProfileAnalytics::create([
                    'card_id'    => $card->id,
                    'user_id'    => $request->user_id,
                    'action'     => 'meeting',
                    'ip_address' => $request->ip(),
                ]);
            }
        }

        $user = User::find($request->user_id);

        if ($user) {

            try {

                $meetingType = ucfirst($meeting->meeting_type);

                $locationText = '';

                if ($meeting->meeting_type == 'offline') {
                    $locationText = "\nLocation:\n{$meeting->location}\n";
                }

                $message = "📅 New {$meetingType} Meeting Request

Hi {$user->name},

You have received a new meeting request through Mi Profile.

Requester Name: {$meeting->requester_name}
Company: {$meeting->company_name}
Email: {$meeting->requester_email}

Meeting Type:
{$meetingType}
{$locationText}
Meeting Title:
{$meeting->title}

Date:
{$meeting->meeting_date}

Time:
{$meeting->meeting_time}

Please log in to your Mi Profile dashboard to review and confirm the meeting request.

Team Mi Profile
Connect Beyond Contacts.";

                app(\App\Services\Messenger360Service::class)
                    ->send($user->phone, $message);

            } catch (\Exception $e) {

                \Log::error('Meeting Notification Failed', [
                    'meeting_id' => $meeting->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Meeting request sent successfully.',
            'data'    => $meeting,
        ], 201);
    }
    /**
     * Get all meetings for the authenticated user (owner/staff).
     */
    public function myMeetings()
    {
        $meetings = Meeting::where('user_id', auth()->id())
            ->orderBy('meeting_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $meetings->map(function ($m) {
                return [
                    'id'                => $m->id,
                    'date'              => $m->meeting_date,
                    'time'              => date('gA', strtotime($m->meeting_time)),
                    'title'             => $m->title,
                    'desc'              => $m->description,
                    'phone'             => $m->phone,
                    'meeting_type'      => $m->meeting_type,
                    'meeting_location'  => $m->location,
                    'company_name'      => $m->company_name,
                    'status'            => $m->status,
                    'name'              => $m->requester_name,
                    'email'             => $m->requester_email,
                    'meetLink'          => $m->meet_link,
                    'googleEventId'     => $m->google_event_id,

                    // Cancellation Details
                    'cancelled_by'      => $m->cancelled_by,
                    'cancelled_remarks' => $m->cancelled_remarks,
                    'cancelled_at'      => $m->cancelled_at
                        ? $m->cancelled_at->format('d M Y h:i A')
                        : null,
                ];
            }),
        ]);
    }

    public function confirm_w($id)
    {
        $meeting = Meeting::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $meeting) {
            return response()->json([
                'success' => false,
                'message' => 'Meeting not found',
            ], 404);
        }

        try {

            // ================= DATE =================

// Start Time
            $startDateTime = Carbon::parse(
                $meeting->meeting_date . ' ' . $meeting->meeting_time,
                'Asia/Kolkata'
            )->toIso8601String();

// End Time (+1 Hour)
            $endDateTime = Carbon::parse(
                $meeting->meeting_date . ' ' . $meeting->meeting_time,
                'Asia/Kolkata'
            )->addHour()->toIso8601String();
            // ================= GOOGLE CALENDAR (using employee's own OAuth) =================

            $user = auth()->user();

            // Use employee's own Google Calendar if they have linked their account
            if ($user && $user->google_token) {
                $calendarService = new UserGoogleCalendarService($user);
            } else {
                // Fallback: use service account if employee hasn't linked Google
                $calendarService = new GoogleCalendarService();
            }

            $eventData = [

                'summary'     => 'Meeting: ' . $meeting->title,

                'description' =>
                ($meeting->description
                        ? $meeting->description . "\n\n"
                        : '') .

                "Meeting requested by: {$meeting->requester_name}
                ({$meeting->requester_email})",

                'startDateTime' => $startDateTime,

                'endDateTime'   => $endDateTime,

                'timezone'      => 'Asia/Kolkata',
            ];

            // ================= CREATE EVENT =================

            $result = $calendarService->createEvent($eventData);
            \Log::info('Calendar Event', [
                'id'          => $result['id'] ?? null,
                'htmlLink'    => $result['htmlLink'] ?? null,
                'hangoutLink' => $result['hangoutLink'] ?? null,
            ]);

            if (! $result || ! isset($result['id'])) {

                return response()->json([
                    'success' => false,
                    'message' => 'Google Calendar event creation failed',
                ], 500);
            }

            // ================= GET REAL GOOGLE MEET LINK =================

            $meetLink = $result['hangoutLink'] ?? null;

            if (! $meetLink && isset($result['conferenceData'])) {

                $conferenceData = $result['conferenceData'];

                if (
                    isset($conferenceData['entryPoints'][0]['uri'])
                ) {
                    $meetLink =
                        $conferenceData['entryPoints'][0]['uri'];
                }
            }

            if (! $meetLink) {

                return response()->json([
                    'success' => false,
                    'message' => 'Google Meet link was not generated',
                ], 500);
            }

            // ================= SAVE =================

            $meeting->update([
                'status'          => 'confirmed',
                'meet_link'       => $meetLink,
                'google_event_id' => $result['id'],
            ]);

            // ================= SEND MAIL =================

            if ($meeting->requester_email) {

                Mail::to($meeting->requester_email)
                    ->send(
                        new MeetingConfirmationMail(
                            $meeting,
                            $meetLink
                        )
                    );
            }

            try {

                $meetingDetailsLink = config('app.frontend_url') . '/meetings';

                $messenger = app(\App\Services\Messenger360Service::class);

                // ================= INVITEE =================

                if ($meeting->phone) {

                    $inviteeMessage = "Hi {$meeting->requester_name} ✨

Your meeting is created.

It's now added inside Mi Profile and ready for the next step.

With: {$user->name}

Date: {$meeting->meeting_date}

Time: {$meeting->meeting_time}

Google Meet:
{$meetLink}

View details:
{$meetingDetailsLink}

Team Mi Profile";

                    $messenger->send(
                        $meeting->phone,
                        $inviteeMessage
                    );
                }

                // ================= HOST =================

                if ($user->phone) {

                    $hostMessage = "📅 Meeting Confirmed

Hi {$user->name} ✨

You have confirmed a meeting request.

Invitee:
{$meeting->requester_name}

Company:
{$meeting->company_name}

Date:
{$meeting->meeting_date}

Time:
{$meeting->meeting_time}

Google Meet:
{$meetLink}

Team Mi Profile";

                    $messenger->send(
                        $user->phone,
                        $hostMessage
                    );
                }

            } catch (\Exception $whatsappException) {

                \Log::error('Meeting WhatsApp Failed', [
                    'meeting_id' => $meeting->id,
                    'error'      => $whatsappException->getMessage(),
                ]);
            }

            return response()->json([
                'success'  => true,
                'message'  => 'Meeting confirmed successfully',
                'meetLink' => $meetLink,
            ]);

        } catch (\Exception $e) {

            \Log::error(
                'Meeting confirmation failed: ' .
                $e->getMessage(),
                [
                    'meeting_id' => $meeting->id,
                    'trace'      => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function confirm($id)
    {
        $meeting = Meeting::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $meeting) {
            return response()->json([
                'success' => false,
                'message' => 'Meeting not found',
            ], 404);
        }

        try {

            // ================= DATE =================

// Start Time
            $startDateTime = Carbon::parse(
                $meeting->meeting_date . ' ' . $meeting->meeting_time,
                'Asia/Kolkata'
            )->toIso8601String();

// End Time (+1 Hour)
            $endDateTime = Carbon::parse(
                $meeting->meeting_date . ' ' . $meeting->meeting_time,
                'Asia/Kolkata'
            )->addHour()->toIso8601String();
            // ================= GOOGLE CALENDAR (using employee's own OAuth) =================

            $user = auth()->user();

            // ================= OFFLINE MEETING =================
            if ($meeting->meeting_type === 'offline') {

                $meeting->update([
                    'status'          => 'confirmed',
                    'meet_link'       => null,
                    'google_event_id' => null,
                ]);

                if ($meeting->requester_email) {
                    Mail::to($meeting->requester_email)
                        ->send(new MeetingConfirmationMail($meeting, null));
                }

                try {

                    $messenger = app(\App\Services\Messenger360Service::class);

                    if ($meeting->phone) {

                        $inviteeMessage = "Hi {$meeting->requester_name} ✨

Your offline meeting has been confirmed.

With: {$user->name}

Date: {$meeting->meeting_date}

Time: {$meeting->meeting_time}

Location:
{$meeting->location}

Team Mi Profile";

                        $messenger->send($meeting->phone, $inviteeMessage);
                    }

                    if ($user->phone) {

                        $hostMessage = "📅 Offline Meeting Confirmed

Hi {$user->name}

Invitee:
{$meeting->requester_name}

Company:
{$meeting->company_name}

Date:
{$meeting->meeting_date}

Time:
{$meeting->meeting_time}

Location:
{$meeting->location}

Team Mi Profile";

                        $messenger->send($user->phone, $hostMessage);
                    }

                } catch (\Exception $e) {

                    \Log::error('Offline Meeting WhatsApp Failed', [
                        'meeting_id' => $meeting->id,
                        'error'      => $e->getMessage(),
                    ]);
                }

                return response()->json([
                    'success'  => true,
                    'message'  => 'Offline meeting confirmed successfully',
                    'meetLink' => null,
                ]);
            }

            // ================= ONLINE MEETING =================

            // Use employee's own Google Calendar if they have linked their account
            if ($user && $user->google_token) {
                $calendarService = new UserGoogleCalendarService($user);
            } else {
                // Fallback: use service account if employee hasn't linked Google
                $calendarService = new GoogleCalendarService();
            }

            $eventData = [

                'summary'     => 'Meeting: ' . $meeting->title,

                'description' =>
                ($meeting->description
                        ? $meeting->description . "\n\n"
                        : '') .

                "Meeting requested by: {$meeting->requester_name}
                ({$meeting->requester_email})",

                'startDateTime' => $startDateTime,

                'endDateTime' => $endDateTime,

                'timezone' => 'Asia/Kolkata',
            ];

            // ================= CREATE EVENT =================

            $result = $calendarService->createEvent($eventData);
            \Log::info('Calendar Event', [
                'id'          => $result['id'] ?? null,
                'htmlLink'    => $result['htmlLink'] ?? null,
                'hangoutLink' => $result['hangoutLink'] ?? null,
            ]);

            if (! $result || ! isset($result['id'])) {

                return response()->json([
                    'success' => false,
                    'message' => 'Google Calendar event creation failed',
                ], 500);
            }

            // ================= GET REAL GOOGLE MEET LINK =================

            $meetLink = $result['hangoutLink'] ?? null;

            if (! $meetLink && isset($result['conferenceData'])) {

                $conferenceData = $result['conferenceData'];

                if (
                    isset($conferenceData['entryPoints'][0]['uri'])
                ) {
                    $meetLink =
                        $conferenceData['entryPoints'][0]['uri'];
                }
            }

            if (! $meetLink) {

                return response()->json([
                    'success' => false,
                    'message' => 'Google Meet link was not generated',
                ], 500);
            }

            // ================= SAVE =================

            $meeting->update([
                'status'          => 'confirmed',
                'meet_link'       => $meetLink,
                'google_event_id' => $result['id'],
            ]);

            // ================= SEND MAIL =================

            if ($meeting->requester_email) {

                Mail::to($meeting->requester_email)
                    ->send(
                        new MeetingConfirmationMail(
                            $meeting,
                            $meetLink
                        )
                    );
            }

            try {

                $meetingDetailsLink = config('app.frontend_url') . '/meetings';

                $messenger = app(\App\Services\Messenger360Service::class);

                // ================= INVITEE =================

                if ($meeting->phone) {

                    $inviteeMessage = "Hi {$meeting->requester_name} ✨

Your meeting is created.

It's now added inside Mi Profile and ready for the next step.

With: {$user->name}

Date: {$meeting->meeting_date}

Time: {$meeting->meeting_time}

Google Meet:
{$meetLink}

View details:
{$meetingDetailsLink}

Team Mi Profile";

                    $messenger->send(
                        $meeting->phone,
                        $inviteeMessage
                    );
                }

                // ================= HOST =================

                if ($user->phone) {

                    $hostMessage = "📅 Meeting Confirmed

Hi {$user->name} ✨

You have confirmed a meeting request.

Invitee:
{$meeting->requester_name}

Company:
{$meeting->company_name}

Date:
{$meeting->meeting_date}

Time:
{$meeting->meeting_time}

Google Meet:
{$meetLink}

Team Mi Profile";

                    $messenger->send(
                        $user->phone,
                        $hostMessage
                    );
                }

            } catch (\Exception $whatsappException) {

                \Log::error('Meeting WhatsApp Failed', [
                    'meeting_id' => $meeting->id,
                    'error'      => $whatsappException->getMessage(),
                ]);
            }

            return response()->json([
                'success'  => true,
                'message'  => 'Meeting confirmed successfully',
                'meetLink' => $meetLink,
            ]);

        } catch (\Exception $e) {

            \Log::error(
                'Meeting confirmation failed: ' .
                $e->getMessage(),
                [
                    'meeting_id' => $meeting->id,
                    'trace'      => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ICS calendar download for a confirmed meeting.
     */
    public function downloadIcs($id)
    {
        $meeting = Meeting::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $meeting || ! $meeting->meet_link) {
            return response()->json([
                'success' => false,
                'message' => 'Meeting not found or not confirmed',
            ], 404);
        }

        $ics = $this->generateIcsContent($meeting);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="meeting_' . $meeting->id . '.ics"',
        ]);
    }

    /**
     * Delete a meeting and its Google Calendar event.
     */
    public function destroy($id)
    {
        $meeting = Meeting::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $meeting) {
            return response()->json([
                'success' => false,
                'message' => 'Meeting not found',
            ], 404);
        }

        // Delete Google Calendar event if it exists
        if ($meeting->google_event_id) {
            try {
                $user = auth()->user();
                if ($user && $user->google_token) {
                    $calendarService = new UserGoogleCalendarService($user);
                } else {
                    $calendarService = new GoogleCalendarService();
                }
                $deleted = $calendarService->deleteEvent($meeting->google_event_id);
                if ($deleted) {
                    \Log::info('Google Calendar event deleted: ' . $meeting->google_event_id . ' for meeting #' . $meeting->id);
                }
            } catch (\Exception $e) {
                \Log::error('Google Calendar event deletion failed: ' . $e->getMessage());
            }
        }

        $meeting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Meeting deleted successfully',
        ]);
    }

    /**
     * Generate ICS calendar file content.
     */
    private function generateIcsContent(Meeting $meeting): string
    {
        $time = date('H:i:s', strtotime($meeting->meeting_time));
        $date = $meeting->meeting_date;

        $dtStart = date('Ymd\THis', strtotime("$date $time"));
        $dtEnd   = date('Ymd\THis', strtotime("$date $time + 1 hour"));

        $uid = 'meeting-' . $meeting->id . '@hamsinisilks.com';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Hamsini Silks//Meeting Calendar//EN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTART:' . $dtStart,
            'DTEND:' . $dtEnd,
            'SUMMARY:' . $this->escapeIcs($meeting->title),
            'DESCRIPTION:' . $this->escapeIcs($meeting->description ?? 'No description'),
            'LOCATION:Google Meet - ' . $meeting->meet_link,
            'ORGANIZER;CN=Hamsini Silks:mailto:info@hamsinisilks.com',
            'ATTENDEE;CN=' . $this->escapeIcs($meeting->requester_name) . ':mailto:' . $meeting->requester_email,
            'STATUS:CONFIRMED',
            'BEGIN:VALARM',
            'TRIGGER:-PT15M',
            'ACTION:DISPLAY',
            'DESCRIPTION:Reminder: Meeting in 15 minutes',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines);
    }

    private function escapeIcs(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace([',', ';', "\n", "\r"], ['\,', '\;', '\\n', ''], $value);
    }

    public function cancelByHost(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $meeting = Meeting::find($id);

        if (! $meeting) {
            return response()->json([
                'success' => false,
                'message' => 'Meeting not found',
            ], 404);
        }

        try {

            // Delete Google Calendar Event
            if ($meeting->google_event_id) {

                $host = $meeting->user;

                if ($host && $host->google_token) {

                    $calendarService =
                    new UserGoogleCalendarService($host);

                    $calendarService->deleteEvent(
                        $meeting->google_event_id
                    );
                }
            }

            // Update Meeting
            $meeting->update([
                'status'            => 'cancelled',
                'cancelled_by'      => auth()->user()->name ?? $meeting->requester_name,
                'cancelled_remarks' => $request->remarks ?? "No Remarks",
                'cancelled_at'      => now(),
            ]);

            // Send Mail to Invitee
            if ($meeting->requester_email) {

                Mail::to($meeting->requester_email)
                    ->send(
                        new MeetingCancelledMail($meeting)
                    );
            }

            return response()->json([
                'success' => true,
                'message' => 'Meeting cancelled successfully',
            ]);

        } catch (\Exception $e) {

            \Log::error(
                'Meeting cancellation failed',
                [
                    'meeting_id' => $meeting->id,
                    'error'      => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getMeetingForCancel($id)
    {
        $meeting = Meeting::where('google_event_id', $id)->first();

        if (! $meeting) {
            return response()->json([
                'success' => false,
                'message' => 'Meeting not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $meeting,
        ]);
    }

    public function cancelByGuest(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $meeting = Meeting::where('google_event_id', $id)->first();

        if (! $meeting) {
            return response()->json([
                'success' => false,
                'message' => 'Meeting not found',
            ], 404);
        }

        try {

            // Delete Google Calendar Event
            if ($meeting->google_event_id) {

                $host = $meeting->user;

                if ($host && $host->google_token) {

                    $calendarService =
                    new UserGoogleCalendarService($host);

                    $calendarService->deleteEvent(
                        $meeting->google_event_id
                    );
                }
            }

            // Update Meeting
            $meeting->update([
                'status'            => 'cancelled',
                'cancelled_by'      => auth()->user()->name ?? $meeting->requester_name,
                'cancelled_remarks' => $request->remarks ?? "No Remarks",
                'cancelled_at'      => now(),
            ]);

            // Send Mail to Invitee
            if ($meeting->requester_email) {

                Mail::to($meeting->requester_email)
                    ->send(
                        new MeetingCancelledMail($meeting)
                    );
            }

            if ($meeting->user && $meeting->user->email) {
                Mail::to($meeting->user->email)
                    ->send(new MeetingCancelledMail($meeting));
            }

            try {

                $messenger = app(\App\Services\Messenger360Service::class);

                // ================= INVITEE =================

                if (! empty($meeting->phone)) {

                    $inviteeMessage = "❌ Meeting Cancelled

Hi {$meeting->requester_name},

Your meeting has been cancelled.

Meeting Title:
{$meeting->title}

Date:
{$meeting->meeting_date}

Time:
{$meeting->meeting_time}

Cancelled By:
" . ($meeting->cancelled_by ?? 'Guest') . "

Reason:
" . ($meeting->cancelled_remarks ?? 'No Remarks') . "

Team Mi Profile
Connect Beyond Contacts.";

                    $messenger->send(
                        (string) $meeting->phone,
                        $inviteeMessage
                    );
                }

                // ================= HOST =================

                if (
                    $meeting->user &&
                    ! empty($meeting->user->phone)
                ) {

                    $hostMessage = "❌ Meeting Cancelled

Hi {$meeting->user->name},

A meeting has been cancelled.

Requester:
{$meeting->requester_name}

Company:
{$meeting->company_name}

Date:
{$meeting->meeting_date}

Time:
{$meeting->meeting_time}

Cancelled By:
" . ($meeting->cancelled_by ?? 'Guest') . "

Reason:
" . ($meeting->cancelled_remarks ?? 'No Remarks') . "

Team Mi Profile
Connect Beyond Contacts.";

                    $messenger->send(
                        (string) $meeting->user->phone,
                        $hostMessage
                    );
                }

            } catch (\Exception $e) {

                \Log::error('Meeting Cancellation WhatsApp Failed', [
                    'meeting_id' => $meeting->id,
                    'message'    => $e->getMessage(),
                    'line'       => $e->getLine(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Meeting cancelled successfully',
            ]);

        } catch (\Exception $e) {

            \Log::error(
                'Meeting cancellation failed',
                [
                    'meeting_id' => $meeting->id,
                    'error'      => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

}