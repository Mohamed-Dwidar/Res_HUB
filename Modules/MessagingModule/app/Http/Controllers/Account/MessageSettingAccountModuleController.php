<?php

namespace Modules\MessagingModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\MessagingModule\app\Http\Requests\MessageSettingRequest;
use Modules\MessagingModule\app\Http\Requests\MessageTemplateRequest;
use Modules\MessagingModule\app\Services\MessagingService;

/**
 * Settings › WhatsApp Messages (the account only): the settings form (on / off, default language)
 * + the messages table (2026-10-31: none until the owner adds one; each = name + "Suggest when" + texts + on / off + its units; add / edit /
 * delete in the popup, live preview with the account's newest subscription).
 */
class MessageSettingAccountModuleController extends Controller
{
    private $messagingService;

    public function __construct(MessagingService $messagingService)
    {
        $this->messagingService = $messagingService;
    }

    private function accountId()
    {
        return Auth::user()->userable->id;
    }

    public function edit()
    {
        $templates = $this->messagingService->templates($this->accountId());
        $templates->load(['units' => fn ($query) => $query->with('space')]);

        return view('messagingmodule::Setting.messages', [
            'settings' => $this->messagingService->settings($this->accountId()),
            'templates' => $templates,
        ]);
    }

    public function update(MessageSettingRequest $request)
    {
        $this->messagingService->saveSettings($this->accountId(), $request->validated());

        return response()->json([
            'message' => 'The settings have been saved.',
            'redirect' => route('account.settings.messages.edit'),
        ]);
    }

    // + New Message
    public function createTemplate()
    {
        return view('messagingmodule::Setting.template-create', $this->templateFormData(null) + ['template' => null]);
    }

    public function storeTemplate(MessageTemplateRequest $request)
    {
        $this->messagingService->createTemplate($this->accountId(), $request->validated(), Auth::user());

        return response()->json([
            'message' => 'The message has been added.',
            'redirect' => route('account.settings.messages.edit'),
        ]);
    }

    public function editTemplate($id)
    {
        $template = $this->messagingService->findTemplate($this->accountId(), $id);
        return view('messagingmodule::Setting.template-edit', $this->templateFormData($template->id) + compact('template'));
    }

    public function updateTemplate(MessageTemplateRequest $request, $id)
    {
        $this->messagingService->updateTemplate($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The message has been saved.',
            'redirect' => route('account.settings.messages.edit'),
        ]);
    }

    public function destroyTemplate($id)
    {
        $this->messagingService->deleteTemplate($this->accountId(), $id);

        return response()->json([
            'message' => 'The message has been deleted.',
            'redirect' => route('account.settings.messages.edit'),
        ]);
    }

    /**
     * The form's lists: "Suggest when", the "Start from" texts ({key: {name, suggest_when, ar, en}}), the spaces with their units,
     * the units' other messages per "Suggest when" (hints), the live preview values.
     */
    private function templateFormData(?int $templateId): array
    {
        $starts = collect(config('messagingmodule.starts'));

        return [
            'suggestOptions' => collect(MessagingService::suggestOptions())->map(fn ($option) => $option['label'])->all(),
            'spaces' => $this->messagingService->spacesWithUnits($this->accountId()),
            'taken' => $this->messagingService->unitTypesTaken($this->accountId(), $templateId),
            'startOptions' => $starts->map(fn ($start) => $start['label'] . ' (' . MessagingService::suggestLabel($start['suggest_when']) . ')')->all(),
            'starts' => $starts->map(fn ($start) => ['name' => $start['label'], 'suggest_when' => $start['suggest_when'], 'ar' => $start['ar'], 'en' => $start['en']])->all(),
            'sample' => $this->messagingService->sampleVariables($this->accountId()),
        ];
    }
}
