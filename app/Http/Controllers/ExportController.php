<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\Contact;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ContactsImport;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function contacts(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = $user->isAdmin() ? Contact::query() : Contact::where('assigned_to', $user->id);

        if ($request->status) $query->where('status', $request->status);

        $contacts = $query->with('assignedUser')
            ->withCount('callLogs')
            ->get();

        return response()->streamDownload(function () use ($contacts) {
            $csv = Writer::createFromFileObject(new \SplTempFileObject());
            $csv->insertOne([
                'Name',
                'Company',
                'Designation',
                'Email',
                'Phone',
                'Status',
                'Priority',
                'Industry',
                'Source',
                'AI Score',
                'LinkedIn URL',
                'LinkedIn Connected',
                'Location',
                'Total Calls',
                'Assigned To',
                'Last Contacted',
                'Created At'
            ]);

            foreach ($contacts as $c) {
                $csv->insertOne([
                    $c->name ?? '-',
                    $c->company ?? '-',
                    $c->designation ?? '-',
                    $c->email ?? '-',
                    $c->phone ?? '-',

                    $c->status ?? '-',
                    $c->priority ?? '-',
                    $c->industry ?? '-',
                    $c->source ?? '-',
                    $c->ai_score ?? '-',

                    $c->linkedin_url ?? '-',
                    $c->linkedin_connected ? 'Yes' : 'No',
                    $c->location ?? '-',

                    $c->call_logs_count ?? '-',
                    $c->assignedUser?->name ?? '-',

                    $c->last_contacted_at
                        ? $c->last_contacted_at->format('Y-m-d H:i')
                        : '-',

                    $c->created_at
                        ? $c->created_at->format('Y-m-d H:i')
                        : '-',
                ]);
            }

            echo $csv->toString();
        }, 'nexevo_contacts_' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240'
        ]);

        $import = new ContactsImport;

        Excel::import($import, $request->file('file'));

        if ($import->hasErrors()) {

            return response()->json([
                'success' => false,
                'message' => 'Leads upload failed. Please check your email for the detailed error report.'
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Leads uploaded successfully'
        ]);
    }

    public function calls(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = $user->isAdmin() ? CallLog::query() : CallLog::where('user_id', $user->id);

        if ($request->date_from) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->date_to) $query->whereDate('created_at', '<=', $request->date_to);

        $calls = $query->with(['contact', 'user'])->latest()->get();

        return response()->streamDownload(function () use ($calls) {
            $csv = Writer::createFromFileObject(new \SplTempFileObject());
            $csv->insertOne([
                'Date',
                'Contact Name',
                'Company',
                'Phone',
                'Sales Rep',
                'Status',
                'Outcome',
                'Duration (secs)',
                'Sentiment',
                'Interest Level',
                'Notes',
                'AI Summary',
                'Next Action'
            ]);

            foreach ($calls as $c) {
                $csv->insertOne([
                    $c->created_at->format('Y-m-d H:i'),
                    $c->contact?->name,
                    $c->contact?->company,
                    $c->contact?->phone,
                    $c->user?->name,
                    $c->status,
                    $c->outcome,
                    $c->duration,
                    $c->sentiment,
                    $c->interest_level,
                    $c->notes,
                    $c->ai_summary,
                    $c->next_action,
                ]);
            }

            echo $csv->toString();
        }, 'nexevo_calls_' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function fullReport(Request $request): StreamedResponse
    {
        return $this->calls($request);
    }
}
