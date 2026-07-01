<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ImportController extends Controller
{
    private const ACTIVITY_COLUMNS = [
        'deal_id', 'date_utc', 'epic', 'instrument', 'direction', 'size', 'level', 'open_price', 'source',
    ];

    private const TRANSACTION_COLUMNS = [
        'reference', 'deal_id', 'date_utc', 'instrument', 'transaction_type', 'pl_chf', 'note',
    ];

    public function store(Request $request)
    {
        $validated = $request->validate([
            'activities' => 'sometimes|array',
            'activities.*.deal_id' => 'required|string',
            'activities.*.date_utc' => 'required|string',
            'activities.*.epic' => 'sometimes|nullable|string',
            'activities.*.instrument' => 'sometimes|nullable|string',
            'activities.*.direction' => 'sometimes|nullable|string',
            'activities.*.size' => 'sometimes|nullable|numeric',
            'activities.*.level' => 'sometimes|nullable|numeric',
            'activities.*.open_price' => 'sometimes|nullable|numeric',
            'activities.*.source' => 'sometimes|nullable|string',
            'transactions' => 'sometimes|array',
            'transactions.*.reference' => 'required|string',
            'transactions.*.deal_id' => 'sometimes|nullable|string',
            'transactions.*.date_utc' => 'sometimes|nullable|string',
            'transactions.*.instrument' => 'sometimes|nullable|string',
            'transactions.*.transaction_type' => 'sometimes|nullable|string',
            'transactions.*.pl_chf' => 'sometimes|nullable|numeric',
            'transactions.*.note' => 'sometimes|nullable|string',
        ]);

        $activityCount = 0;
        foreach ($validated['activities'] ?? [] as $activity) {
            Activity::upsert(Arr::only($activity, self::ACTIVITY_COLUMNS), ['deal_id', 'date_utc']);
            $activityCount++;
        }

        $transactionCount = 0;
        foreach ($validated['transactions'] ?? [] as $transaction) {
            $data = Arr::only($transaction, self::TRANSACTION_COLUMNS);
            Transaction::updateOrCreate(['reference' => $data['reference']], $data);
            $transactionCount++;
        }

        return response()->json([
            'message' => "✅ {$activityCount} Activities, {$transactionCount} Transactions importiert",
        ]);
    }
}
