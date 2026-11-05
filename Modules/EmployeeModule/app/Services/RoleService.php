<?php

namespace Modules\EmployeeModule\app\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\EmployeeModule\app\Models\Role;
use Modules\EmployeeModule\app\Repositories\RoleRepository;
use Modules\UserModule\app\Models\User;

/**
 * Roles & permissions (docs/roles-plan.md, R0 2026-11-03). Every role method takes the owner account id.
 * - the catalog = config employeemodule.permissions (groups → key => label) + permission_requires;
 * - allows() = the Gate::before rule (EmployeeModuleServiceProvider): the owner = every ability, an employee = his role's keys
 *   (other abilities: null = the Gate's own definitions);
 * - an employee without a role / with a deleted one = the account's default role (Front desk), made on demand when missing.
 */
class RoleService
{
    private static ?array $keys = null;

    // [path regex, methods, abilities] of the employee routes, built once per request (allowsUrl())
    private ?array $routeRules = null;

    public function __construct(private RoleRepository $roleRepository)
    {
    }

    // ---- the catalog ----

    // [group => [key => label]]
    public function catalog(): array
    {
        return config('employeemodule.permissions', []);
    }

    // every key, in catalog order
    public function keys(): array
    {
        return self::$keys ??= array_merge(...array_values(array_map('array_keys', $this->catalog())));
    }

    // [key => [keys it brings]]
    public function requires(): array
    {
        return config('employeemodule.permission_requires', []);
    }

    // known keys only + what they need, in catalog order
    public function normalize(array $keys): array
    {
        $requires = $this->requires();
        $set = array_flip(array_intersect($keys, $this->keys()));
        do {
            $before = count($set);
            foreach (array_keys($set) as $key) {
                foreach ($requires[$key] ?? [] as $needed) {
                    $set[$needed] = true;
                }
            }
        } while (count($set) !== $before);

        return array_values(array_filter($this->keys(), fn ($key) => isset($set[$key])));
    }

    public function presets(): array
    {
        return config('employeemodule.role_presets', []);
    }

    // ---- the Gate ----

    // Gate::before: true / false for a catalog key, the owner always true; null = not ours
    public function allows($user, string $ability): ?bool
    {
        if (!$user instanceof User) {
            return null;
        }
        $userable = $user->userable;
        if ($userable instanceof Account) {
            return true;
        }
        if ($userable instanceof Employee && in_array($ability, $this->keys(), true)) {
            return in_array($ability, $userable->permissions(), true);
        }
        return null;
    }

    // the employee's permission keys (Employee::permissions() keeps them for the request)
    public function permissionsOf(Employee $employee): array
    {
        $role = $this->roleOf($employee);
        return $role ? (array) $role->permissions : $this->presets()['front_desk']['permissions'];
    }

    // the employee's role (the default one when he has none / his was deleted); null only before the roles migration ran
    public function roleOf(Employee $employee): ?Role
    {
        $role = $employee->role_id ? $employee->role : null;
        return $role && (int) $role->account_id === (int) $employee->account_id
            ? $role
            : Role::where('account_id', $employee->account_id)->where('is_default', true)->first();
    }

    // reservations create / store: a Renew (?renew= / renewal_of) needs reservations.renew, else reservations.create
    public function reservationCreateAbility(bool $renew): string
    {
        return $renew ? 'reservations.renew' : 'reservations.create';
    }

    // the employee's first page: the Schedule, else the first page he may open (config home_pages), else his profile
    public function homeRoute(Employee $employee): string
    {
        foreach (config('employeemodule.home_pages', []) as $ability => $route) {
            if (in_array($ability, $employee->permissions(), true)) {
                return $route;
            }
        }
        return 'employee.dashboard';
    }

    // may the logged in user open this url (row menus hide the links he can't open)? The owner: always; an employee: the
    // route's can: abilities. Matched without binding the route (the current request's route keeps its parameters).
    public function allowsUrl(string $url, string $method = 'GET'): bool
    {
        if (!Auth::user()?->userable instanceof Employee) {
            return true;
        }

        $path = '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        foreach ($this->routeRules() as [$regex, $methods, $abilities, $name]) {
            if (!in_array($method, $methods, true) || !preg_match($regex, $path)) {
                continue;
            }
            if ($name === 'employee.reservations.create') {
                $abilities = [$this->reservationCreateAbility(str_contains($url, 'renew='))];
            }
            foreach ($abilities as $ability) {
                if (Gate::denies($ability)) {
                    return false;
                }
            }
            return true;
        }
        return true;
    }

    private function routeRules(): array
    {
        if ($this->routeRules !== null) {
            return $this->routeRules;
        }
        $this->routeRules = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with((string) $route->getName(), 'employee.')) {
                continue;
            }
            $abilities = [];
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $abilities[] = explode(',', substr($middleware, 4))[0];
                }
            }
            $this->routeRules[] = [$route->toSymfonyRoute()->compile()->getRegex(), $route->methods(), $abilities, $route->getName()];
        }
        return $this->routeRules;
    }

    // ---- the roles (Settings › Roles) ----

    public function listQuery($accountId)
    {
        $this->defaultRole($accountId);
        return $this->roleRepository->forAccount($accountId);
    }

    // 404 when it belongs to another account
    public function findOne($accountId, $id): Role
    {
        return $this->roleRepository->forAccount($accountId)->findOrFail($id);
    }

    // the account's default role ("Front desk"), made from the preset when missing (an account registered after R0)
    public function defaultRole($accountId): Role
    {
        $role = Role::where('account_id', $accountId)->where('is_default', true)->first();
        if ($role) {
            return $role;
        }
        $preset = $this->presets()['front_desk'];
        return Role::create([
            'account_id' => $accountId, 'name' => $preset['name'], 'permissions' => $preset['permissions'],
            'idle_hours' => null, 'is_default' => true, 'created_by' => 0,
        ]);
    }

    // [id => name] for the employee form, the default role first
    public function options($accountId): array
    {
        $this->defaultRole($accountId);
        return Role::where('account_id', $accountId)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all();
    }

    // the Add Role form's values: a preset (default Front desk) or a copy of a role (?from=, "Duplicate")
    public function startValues($accountId, ?string $preset, $fromId = null): array
    {
        if ($fromId) {
            $role = $this->findOne($accountId, $fromId);
            return ['name' => $role->name . ' (copy)', 'permissions' => (array) $role->permissions, 'idle_hours' => $role->idle_hours];
        }
        $presets = $this->presets();
        $chosen = $presets[$preset] ?? $presets['front_desk'];
        return ['name' => $preset && isset($presets[$preset]) && $preset !== 'empty' ? $chosen['name'] : '', 'permissions' => $chosen['permissions'], 'idle_hours' => null];
    }

    public function create($accountId, array $data, $userId): Role
    {
        return Role::create($this->roleData($data) + ['account_id' => $accountId, 'is_default' => false, 'created_by' => (int) $userId]);
    }

    public function update($accountId, $id, array $data): Role
    {
        $role = $this->findOne($accountId, $id);
        $role->update($this->roleData($data));
        return $role;
    }

    // soft delete; refused for the default role and a role employees still have
    public function deleteOne($accountId, $id): void
    {
        $role = $this->findOne($accountId, $id);
        if ($role->is_default) {
            throw ValidationException::withMessages(['role' => 'The default role can\'t be deleted.']);
        }
        if ($role->employees_count > 0) {
            throw ValidationException::withMessages(['role' => 'This role is given to ' . $role->employees_count . ' user(s): give them another role first.']);
        }
        $role->delete();
    }

    private function roleData(array $data): array
    {
        return [
            'name' => $data['name'],
            'permissions' => $this->normalize(array_keys(array_filter((array) ($data['permissions'] ?? [])))),
            'idle_hours' => !empty($data['idle_hours']) ? (int) $data['idle_hours'] : null,
        ];
    }
}
