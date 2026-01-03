<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Models\SupportTicket;
use App\Mail\SupportContactMail;

class SupportController extends Controller
{
    public function submitContact(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|in:Bug Report,Feature Request,Account Issue,General Question',
            'email' => 'required|email',
            'message' => 'required|string|min:10|max:2000',
            'file' => 'nullable|image|mimes:jpeg,png,gif,webp|max:5120'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $filePath = null;

            if ($request->hasFile('file')) {
                $filePath = $request->file('file')->store('support-attachments', 'public');
            }

            $ticket = SupportTicket::create([
                'subject' => $request->subject,
                'email' => $request->email,
                'message' => $request->message,
                'file_path' => $filePath,
                'status' => 'pending'
            ]);

            Mail::send(new SupportContactMail(
                $request->subject,
                $request->email,
                $request->message,
                $filePath
            ));

            return response()->json([
                'success' => true,
                'message' => 'Your message has been received. We\'ll respond within 24 hours.',
                'ticket_id' => $ticket->id
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Support contact submission failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send your message. Please try again later.'
            ], 500);
        }
    }
}