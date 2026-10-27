<?php

namespace Modules\AccountModule\app\Http\Controllers\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Modules\ReservationModule\app\Models\Reservation;
use Illuminate\Support\Facades\Auth;
use Modules\AccountModule\app\Http\Requests\GeneralSettingRequest;
use Modules\AccountModule\app\Models\Account;

/**
 * Settings › General (the account only): withholding tax (default ON; OFF hides new withholding everywhere, the existing records
 * stay) and VAT (default ON; used by Phase 2: OFF = prices tax free). Saving never changes payments / certificates.
 */
class GeneralSettingAccountModuleController extends Controller
{
    public function edit()
    {
        return view('accountmodule::Setting.general', ['account' => Auth::user()->userable]);
    }

    public function update(GeneralSettingRequest $request)
    {
        $account = Auth::user()->userable;
        $account->fill($request->validated());
        // a new tax start date: when it was set (the rows created before this moment are back-dated, Account::isBackdated())
        if ($account->isDirty('tax_start_date')) {
            $account->tax_start_set_at = $account->tax_start_date ? now() : null;
        }
        $account->save();
        Account::forgetSettings($account->id);

        return response()->json([
            'message' => 'The settings have been saved.',
            'redirect' => route('account.settings.general.edit'),
        ]);
    }

    // the Tax Start Date confirmation (custom.js taxStartConfirm()): how many reservations were created since that date
    public function taxCount(Request $request)
    {
        $date = $request->filled('date') && strtotime((string) $request->input('date')) ? Carbon::parse($request->input('date'))->startOfDay() : null;
        if (!$date) {
            return response()->json(['count' => 0, 'message' => null]);
        }
        $count = Reservation::where('account_id', Auth::user()->userable->id)->where('created_at', '>=', $date)->count();

        return response()->json(['count' => $count, 'message' => number_format($count) . ' reservation(s) created since ' . $date->format(Reservation::DATE_FORMAT)
            . ' will be able to have an invoice. Their amounts don\'t change unless "Invoice requested" is ticked.']);
    }
}
