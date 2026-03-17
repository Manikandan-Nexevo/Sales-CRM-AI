<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\CallLog;
use App\Models\FollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Events\ContactUpdated;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->isAdmin() ? Contact::query() : Contact::where('assigned_to', $user->id);

        if ($request->status) $query->where('status', $request->status);
        if ($request->priority) $query->where('priority', $request->priority);
        if ($request->industry) $query->where('industry', $request->industry);
        if ($request->search) $query->search($request->search);

        $contacts = $query->with('assignedUser')
            ->withCount('callLogs')
            ->withCount('followUps')
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_dir ?? 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json($contacts);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'company' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:contacts,phone',
            'phone_alt' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255|unique:contacts,email',
            'designation' => 'nullable|string|max:255',
            'linkedin_url' => 'nullable|url|max:500',
            'industry' => 'nullable|string|max:100',
            'source' => 'nullable|string|max:100',
            'status' => 'nullable|in:new,contacted,interested,qualified,hot,proposal,closed_won,closed_lost,not_interested',
            'priority' => 'nullable|in:low,medium,high',
        ]);

        $formatPhone = function ($phone) {
            if (!$phone) return null;

            $phone = str_replace([' ', '+'], '', $phone);

            $phone = ltrim($phone, '0');
            if (!str_starts_with($phone, '91')) {
                $phone = '91' . $phone;
            }

            return $phone;
        };

        $data = $request->all();

        $data['phone'] = $formatPhone($request->phone);
        $data['phone_alt'] = $formatPhone($request->phone_alt);

        $contact = Contact::create([
            ...$data,
            'assigned_to' => $request->assigned_to ?? $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'contact' => $contact
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $contact = Contact::with([
            'assignedUser',
            'callLogs.user',
            'followUps'
        ])
            ->withCount(['callLogs', 'followUps'])
            ->findOrFail($id);

        return response()->json($contact);
    }

    public function assign(Request $request, $id)
    {
        $request->validate([
            'assigned_to' => 'required|exists:mysql.users,id'
        ]);

        $contact = Contact::findOrFail($id);

        $contact->assigned_to = $request->assigned_to;
        $contact->save();

        return response()->json([
            'message' => 'Lead assigned successfully'
        ]);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $contact->update($request->all());
        return response()->json(['success' => true, 'contact' => $contact]);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();
        return response()->json(['success' => true]);
    }

    public function quickSearch(Request $request): JsonResponse
    {
        $term = $request->q;
        $contacts = Contact::search($term)
            ->select('id', 'name', 'company', 'phone', 'email', 'status', 'ai_score')
            ->take(10)
            ->get();
        return response()->json($contacts);
    }

    public function updateStatus(Request $request, Contact $contact): JsonResponse
    {
        $request->validate(['status' => 'required|in:new,contacted,interested,qualified,hot,proposal,closed_won,closed_lost,not_interested']);
        $contact->update(['status' => $request->status]);
        return response()->json(['success' => true, 'contact' => $contact]);
    }

    public function saveLinkedIn(Request $request, Contact $contact): JsonResponse
    {
        $request->validate(['linkedin_url' => 'required|url', 'connected' => 'boolean']);
        $contact->update([
            'linkedin_url' => $request->linkedin_url,
            'linkedin_connected' => $request->connected ?? false,
        ]);
        return response()->json(['success' => true, 'contact' => $contact]);
    }

    public function timeline(Contact $contact): JsonResponse
    {
        $calls = CallLog::where('contact_id', $contact->id)->with('user')->latest()->get();
        $followups = FollowUp::where('contact_id', $contact->id)->with('user')->latest()->get();

        $timeline = collect();
        foreach ($calls as $call) {
            $timeline->push(['type' => 'call', 'data' => $call, 'date' => $call->created_at]);
        }
        foreach ($followups as $fu) {
            $timeline->push(['type' => 'followup', 'data' => $fu, 'date' => $fu->created_at]);
        }

        return response()->json($timeline->sortByDesc('date')->values());
    }
}
