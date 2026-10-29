<?php

namespace Modules\MessagingModule\app\Http\Controllers\Account;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\MessagingModule\app\Http\Requests\MessageSettingRequest;
use Modules\MessagingModule\app\Http\Requests\MessageTemplateRequest;
use Modules\MessagingModule\app\Services\MessagingService;
use Modules\SpaceModule\app\Services\SpaceService;

/**
 * Settings › WhatsApp Messages (the account only): the settings form (on / off, default language, deposit %, payment details)
 * + the texts table: a default per type (from config the first time the page opens; edit, switch off) and space versions
 * (add / edit / delete in the popup, live preview with the account's newest subscription).
 */
class MessageSettingAccountModuleController extends Controller
{
    private $messagingService;
    private $spaceService;

    public function __construct(MessagingService $messagingService, SpaceService $spaceService)
    {
        $this->messagingService = $messagingService;
        $this->spaceService = $spaceService;
    }

    private function accountId()
    {
        return Auth::user()->userable->id;
    }

    public function edit()
    {
        $this->messagingService->ensureDefaults($this->accountId(), Auth::user());

        return view('messagingmodule::Setting.messages', [
            'settings' => $this->messagingService->settings($this->accountId()),
            'groups' => $this->messagingService->templateGroups($this->accountId()),
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

    // + Space Version (?type= pre-selects the type)
    public function createTemplate()
    {
        return view('messagingmodule::Setting.template-create', $this->templateFormData() + ['template' => null]);
    }

    public function storeTemplate(MessageTemplateRequest $request)
    {
        $this->messagingService->createTemplate($this->accountId(), $request->validated(), Auth::user());

        return response()->json([
            'message' => 'The space version has been added.',
            'redirect' => route('account.settings.messages.edit'),
        ]);
    }

    public function editTemplate($id)
    {
        $template = $this->messagingService->findTemplate($this->accountId(), $id);
        return view('messagingmodule::Setting.template-edit', $this->templateFormData() + compact('template'));
    }

    public function updateTemplate(MessageTemplateRequest $request, $id)
    {
        $this->messagingService->updateTemplate($this->accountId(), $id, $request->validated());

        return response()->json([
            'message' => 'The text has been saved.',
            'redirect' => route('account.settings.messages.edit'),
        ]);
    }

    public function destroyTemplate($id)
    {
        $this->messagingService->deleteTemplate($this->accountId(), $id);

        return response()->json([
            'message' => 'The space version has been deleted.',
            'redirect' => route('account.settings.messages.edit'),
        ]);
    }

    // the form's lists + the live preview values + the "Start from" texts ({type: {default: {ar, en}, <space text key>: {ar, en}}})
    private function templateFormData(): array
    {
        $starts = [];
        foreach (MessagingService::types() as $type => $definition) {
            $default = $this->messagingService->templateFor($this->accountId(), $type, 0) ?? ['ar' => $definition['ar'], 'en' => $definition['en']];
            $starts[$type]['default'] = $default;
            foreach (config('messagingmodule.space_texts') as $key => $spaceText) {
                if (isset($spaceText['texts'][$type])) {
                    $starts[$type][$key] = $spaceText['texts'][$type];
                }
            }
        }

        return [
            'types' => collect(MessagingService::types())->map(fn ($definition) => $definition['label'])->all(),
            'spaces' => $this->spaceService->options($this->accountId()),
            'startOptions' => ['default' => 'The default text of this type'] + collect(config('messagingmodule.space_texts'))->map(fn ($spaceText) => $spaceText['label'] . ' text')->all(),
            'starts' => $starts,
            'sample' => $this->messagingService->sampleVariables($this->accountId()),
        ];
    }
}
