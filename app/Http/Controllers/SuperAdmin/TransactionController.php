<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with('company:id,name');

        if ($s = $request->search) {
            $query->where('transaction_id', 'like', "%$s%")
                ->orWhereHas('company', fn($q) => $q->where('name', 'like', "%$s%"));
        }

        $paginated = $query->latest()->paginate($request->per_page ?? 10);
        $paginated->getCollection()->transform(fn($t) => array_merge(
            $t->toArray(),
            ['company_name' => $t->company?->name]
        ));

        $stats = [
            'total_revenue' => Transaction::where('status', 'success')->where('type', 'payment')->sum('amount'),
            'successful'    => Transaction::where('status', 'success')->count(),
            'failed'        => Transaction::where('status', 'failed')->count(),
            'refunded'      => Transaction::where('type', 'refund')->count(),
        ];

        return response()->json(array_merge($paginated->toArray(), ['stats' => $stats]));
    }
}
