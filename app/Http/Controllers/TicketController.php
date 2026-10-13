<?php
namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [

            'department'             => 'required',
            'urgency'                => 'required',

            'subject'                => 'required|string|max:255',
            'message'                => 'required|string',

            'control_panel_url'      => 'nullable|string',
            'control_panel_username' => 'nullable|string',
            'control_panel_password' => 'nullable|string',

            'attachments.*'          => 'nullable|file|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $ticket = Ticket::create([
                'user_id'                => auth()->id(),
                'ticket_no'              => 'TKT-' . strtoupper(Str::random(8)),
                'department'             => $request->department,
                'urgency'                => $request->urgency,

                'control_panel_url'      => $request->control_panel_url,
                'control_panel_username' => $request->control_panel_username,
                'control_panel_password' => $request->control_panel_password
                    ? encrypt($request->control_panel_password)
                    : null,

                'subject'                => $request->subject,
                'message'                => $request->message,
                'status'                 => 'open',
            ]);

            // Upload Attachments
            if ($request->hasFile('attachments')) {

                foreach ($request->file('attachments') as $file) {

                    $path = $file->store('tickets', 'public');

                    $ticket->attachments()->create([
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                    ]);
                }
            }

            DB::commit();

            $this->sendTicketCreatedEmails($ticket);

            return response()->json([
                'success' => true,
                'message' => 'Ticket submitted successfully',
                'data'    => $ticket->load('attachments'),
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function sendTicketCreatedEmails(Ticket $ticket)
    {
        try {
            $user = $ticket->user;

            \Log::info('Before user mail');

            if ($user && $user->email) {
                \Mail::to($user->email)
                    ->send(new \App\Mail\TicketCreated($ticket));

                \Log::info('User mail sent');
            }

            \Log::info('Before admin mail');

            $adminEmail = env('ADMIN_MAIL');

            \Mail::to($adminEmail)
                ->send(new \App\Mail\TicketCreated($ticket, true));

            \Log::info('Admin mail sent');

        } catch (\Exception $e) {
            \Log::error('Mail Error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    public function index()
    {
        $tickets = Ticket::with([
            'attachments',
            'replies.user',
        ])
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $tickets,
        ]);
    }

    public function allTickets()
    {
        $tickets = Ticket::with([
            'user',
            'attachments',
            'replies.user',
        ])->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $tickets,
        ]);
    }

    public function show($id)
    {
        $ticket = Ticket::with('attachments')
            ->where('user_id', auth()->id())
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $ticket,
        ]);
    }

    public function updateTicketssss(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status'     => 'required',
            'message'    => 'nullable|string',
            'attachment' => 'nullable|file|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $ticket->status = $request->status;
            $ticket->save();

            $attachment = null;

            if ($request->hasFile('attachment')) {
                $attachment = $request
                    ->file('attachment')
                    ->store('ticket-replies', 'public');
            }

            if ($request->filled('message') || $attachment) {

                TicketReply::create([
                    'ticket_id'   => $ticket->id,
                    'user_id'     => auth()->id(),
                    'sender_type' => 'admin',
                    'message'     => $request->message,
                    'attachment'  => $attachment,
                ]);
            }

            DB::commit();

            try {
                $this->sendTicketUpdateEmail($ticket, $reply);
            } catch (\Exception $e) {
                // Log error but don't fail the request
                \Log::error('Failed to send ticket update email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Ticket updated successfully',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateTicket(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status'     => 'required|in:open,in_progress,resolved',
            'message'    => 'nullable|string',
            'attachment' => 'nullable|file|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $ticket->status = $request->status;
            $ticket->save();

            $attachment = null;
            $reply      = null;

            if ($request->hasFile('attachment')) {
                $attachment = $request
                    ->file('attachment')
                    ->store('ticket-replies', 'public');
            }

            // Create reply only if there's a message or attachment
            if ($request->filled('message') || $attachment) {
                $reply = TicketReply::create([
                    'ticket_id'   => $ticket->id,
                    'user_id'     => auth()->id(),
                    'sender_type' => 'admin',
                    'message'     => $request->message ?? '',
                    'attachment'  => $attachment,
                ]);
            }

            DB::commit();

            // Send email (synchronously)
            try {
                $this->sendTicketUpdateEmail($ticket, $reply);
            } catch (\Exception $e) {
                \Log::error('Failed to send ticket update email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Ticket updated successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function sendTicketUpdateEmail(Ticket $ticket, $reply = null)
    {
        $user = $ticket->user;

        if (! $user || ! $user->email) {
            return;
        }

        $data = [
            'ticket' => $ticket,
            'reply'  => $reply,
            'user'   => $user,
        ];

        \Mail::send('emails.ticket-update', $data, function ($message) use ($user, $ticket) {
            $message->to($user->email)
                ->subject('Ticket #' . $ticket->ticket_no . ' Updated - ' . ucfirst($ticket->status));
        });
    }
}
