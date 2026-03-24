<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['company:id,name', 'plan:id,name']);

        if ($s = $request->search) {
            $query->where('invoice_number', 'like', "%$s%")
                ->orWhereHas('company', fn($q) => $q->where('name', 'like', "%$s%"));
        }

        $paginated = $query->latest()->paginate($request->per_page ?? 10);
        $paginated->getCollection()->transform(fn($i) => array_merge(
            $i->toArray(),
            ['company_name' => $i->company?->name, 'plan_name' => $i->plan?->name]
        ));

        $stats = [
            'total'   => Invoice::count(),
            'paid'    => Invoice::where('status', 'paid')->count(),
            'pending' => Invoice::where('status', 'pending')->count(),
            'overdue' => Invoice::where('status', 'overdue')->count(),
        ];

        return response()->json(array_merge($paginated->toArray(), ['stats' => $stats]));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load(['company', 'plan']));
    }

    public function downloadPdf(Invoice $invoice)
    {
        $pdf = \PDF::loadView('invoices.pdf', ['invoice' => $invoice->load(['company', 'plan'])]);
        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
