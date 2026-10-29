<?php

namespace Modules\MessagingModule\app\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\MessagingModule\app\Services\MessagingService;

/**
 * WhatsApp messages actions shared by the account and the employee controllers (both send messages to the account's members).
 * The controller gives area(): "account" | "employee" (route names {area}.messages.*).
 * show = the popup ({kind}/{id}: kind "reservation" now; ?context = the Schedule group / list filter, ?type = a type asked for after
 * a save, ?lang); send = the log of one Send (custom.js: the wa.me link opens in a new tab by itself, this request runs beside it),
 * answered with the popup again (Last sent + history).
 */
trait MessageActions
{
    private $messagingService;

    abstract protected function area(): string;

    public function __construct(MessagingService $messagingService)
    {
        $this->messagingService = $messagingService;
    }

    // the account itself, or the account of the logged in employee
    private function accountId()
    {
        return Auth::user()->userable->ownerAccountId();
    }

    public function show(Request $request, string $kind, $id)
    {
        $reservation = $this->messagingService->findReservation($this->accountId(), $id);
        $context = in_array($request->query('context'), MessagingService::CONTEXTS, true) ? $request->query('context') : null;

        return view('messagingmodule::Message.show', $this->messagingService->popup($reservation, $context, $request->query('type'), $request->query('lang')));
    }

    public function send(Request $request, string $kind, $id)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(MessagingService::types()))],
            'language' => ['required', Rule::in(['ar', 'en'])],
            'context' => ['nullable', Rule::in(MessagingService::CONTEXTS)],
        ]);
        $reservation = $this->messagingService->findReservation($this->accountId(), $id);
        $sent = $this->messagingService->send($this->accountId(), $reservation, $data['type'], $data['language'], Auth::user());

        return response()->json([
            'message' => MessagingService::typeLabel($data['type']) . ' sent to WhatsApp.',
            'url' => $sent['url'],
            'modal' => route($this->area() . '.messages.show', ['reservation', $reservation->id, 'context' => $data['context'] ?? null,
                'type' => $request->input('suggested') ?: null, 'lang' => $data['language']]),
        ]);
    }
}
