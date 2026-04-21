<?php

namespace Guarzo\Seat\WandererSync\Http\Controllers;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Guarzo\Seat\WandererSync\Services\MappingService;
use Guarzo\Seat\WandererSync\Support\Outcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\Acl\Role;

final class SettingsController extends Controller
{
    public function __construct(private readonly MappingService $mappings) {}

    public function list(): View
    {
        return view('wanderer-sync::list', [
            'roles' => WandererAccessListRole::with(['role', 'accessList'])->get(),
            'seat_roles' => Role::all(),
            'wanderer_access_lists' => WandererAccessListInstance::all(),
        ]);
    }

    public function createMapping(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role' => 'required|integer',
            'acl' => 'required|integer',
        ]);

        if (!WandererAccessListInstance::find($data['acl'])) {
            return back()->with('error', trans('wanderer-sync::settings.acl_not_found'));
        }

        return $this->flash($this->mappings->createMapping($data['role'], $data['acl']));
    }

    public function deleteMapping(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => 'required|integer']);
        $this->mappings->deleteMapping($data['id']);
        return back()->with('success', trans('wanderer-sync::settings.mapping_deleted'));
    }

    public function createWandererAccessList(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => 'required|string',
            'id' => 'required|string',
            'token' => 'required|string',
        ]);

        return $this->flash($this->mappings->createInstance($data['url'], $data['id'], $data['token']));
    }

    public function deleteInstance(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => 'required|integer']);
        $this->mappings->deleteInstance($data['id']);
        return back()->with('success', trans('wanderer-sync::settings.instance_deleted'));
    }

    private function flash(Outcome $outcome): RedirectResponse
    {
        if ($outcome->isSuccess()) {
            return back()->with('success', trans('wanderer-sync::settings.added'));
        }
        if ($outcome->isExisted()) {
            return back()->with('warning', trans('wanderer-sync::settings.already_exists'));
        }
        return back()->with('error', trans("wanderer-sync::settings.{$outcome->reasonKey()}"));
    }
}
