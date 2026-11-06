<?php

namespace Modules\EmployeeModule\Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Modules\AccountModule\app\Models\Account;
use Modules\EmployeeModule\app\Models\Employee;
use Modules\EmployeeModule\app\Models\Role;
use Modules\UserModule\app\Models\User;
use Tests\TestCase;

/**
 * Employees reach only what they are given (docs/roles-plan.md). No database: the logged in user / employee / account are in
 * memory (the 403 comes from the middleware before any query).
 * - every route behind the web `auth` middleware names its user type (user.active:X) or is in UNTYPED (logout, Login Details):
 *   a new route without a type fails here;
 * - every account (owner) route answers 403 to an employee.
 * - R0 (roles): every employee route declares its permission (can:…) except PERMISSION_FREE; a role with nothing ticked gets 403
 *   on all of them; Front desk (the preset = the migration's list) passes every one of them (= every employee route of today).
 */
class EmployeeRoutesTest extends TestCase
{
    // web `auth` routes open to any logged in user type
    const UNTYPED = ['logout', 'user.account.edit', 'user.account.update'];

    // employee routes open to every role: his profile (the header menu "My Profile")
    const PERMISSION_FREE = ['employee.dashboard'];

    // permission groups never in Front desk: their routes are for roles made for them (HR, docs/hr-plan.md)
    const NOT_FRONT_DESK = ['hr.', 'documents.', 'users.', 'payroll.', 'reports.'];

    private function notFrontDesk(string $ability): bool
    {
        foreach (self::NOT_FRONT_DESK as $prefix) {
            if (str_starts_with($ability, $prefix)) {
                return true;
            }
        }
        return false;
    }

    // $permissions = his role's keys (in memory, no query): null = Front desk
    private function employeeUser(?array $permissions = null): User
    {
        $account = (new Account())->forceFill(['id' => 999001, 'status' => Account::STATUS_ACTIVE, 'name' => 'Test account']);
        $role = (new Role())->forceFill(['id' => 999004, 'account_id' => 999001, 'name' => 'Test role', 'idle_hours' => null,
            'permissions' => $permissions ?? config('employeemodule.role_presets.front_desk.permissions')]);
        $employee = (new Employee())->forceFill(['id' => 999002, 'account_id' => 999001, 'role_id' => 999004, 'name' => 'Test employee', 'leave_date' => null]);
        $employee->setRelation('account', $account);
        $employee->setRelation('role', $role);
        $user = (new User())->forceFill(['id' => 999003, 'email' => 'employee@test.local']);
        $user->setRelation('userable', $employee);
        return $user;
    }

    // [route, middleware names] of every route behind the web guard's auth
    private function webAuthRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (in_array('auth', $middleware, true) || in_array('auth:web', $middleware, true)) {
                $routes[] = [$route, $middleware];
            }
        }
        return $routes;
    }

    // the user type of user.active:X (null = none)
    private function userType(array $middleware): ?string
    {
        foreach ($middleware as $name) {
            if (is_string($name) && str_starts_with($name, 'user.active:')) {
                return substr($name, strlen('user.active:'));
            }
        }
        return null;
    }

    // a value the route accepts: the first word of a `where` like "reservation|package", else 1
    private function paramValue(RoutingRoute $route, string $name): string
    {
        $where = $route->wheres[$name] ?? null;
        return $where && preg_match('/^[a-z_\-]+(\|[a-z_\-]+)*$/', $where) ? explode('|', $where)[0] : '1';
    }

    private function label(RoutingRoute $route): string
    {
        return ($route->getName() ?? '(no name)') . ' [' . implode('|', $route->methods()) . ' /' . $route->uri() . ']';
    }

    public function test_every_logged_in_route_names_its_user_type(): void
    {
        $untyped = [];
        foreach ($this->webAuthRoutes() as [$route, $middleware]) {
            if (!$this->userType($middleware) && !in_array($route->getName(), self::UNTYPED, true)) {
                $untyped[] = $this->label($route);
            }
        }

        $this->assertSame([], $untyped, "Routes behind auth without user.active:{Type} (any logged in user could open them):\n" . implode("\n", $untyped));
    }

    public function test_every_owner_route_answers_403_to_an_employee(): void
    {
        $user = $this->employeeUser();
        $wrong = [];
        $checked = 0;

        foreach ($this->webAuthRoutes() as [$route, $middleware]) {
            if ($this->userType($middleware) !== Account::class) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($method) => $method !== 'HEAD');
            $uri = '/' . preg_replace_callback('/\{([^}?]+)\??\}/', fn ($match) => $this->paramValue($route, $match[1]), $route->uri());

            $this->actingAs($user, 'web');
            $status = $this->call($method, $uri, [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'])->getStatusCode();
            $checked++;
            if ($status !== 403) {
                $wrong[] = $this->label($route) . ' → ' . $status;
            }
        }

        $this->assertGreaterThan(100, $checked, 'Too few routes checked: the route list did not load.');
        $this->assertSame([], $wrong, "Owner routes an employee opened without a 403:\n" . implode("\n", $wrong));
    }

    // the abilities of a route's can: middleware
    private function abilities(array $middleware): array
    {
        $abilities = [];
        foreach ($middleware as $name) {
            if (is_string($name) && str_starts_with($name, 'can:')) {
                $abilities[] = explode(',', substr($name, 4))[0];
            }
        }
        return $abilities;
    }

    private function employeeRoutes(): array
    {
        return array_values(array_filter($this->webAuthRoutes(), fn ($item) => $this->userType($item[1]) === Employee::class));
    }

    private function uri(RoutingRoute $route): string
    {
        return '/' . preg_replace_callback('/\{([^}?]+)\??\}/', fn ($match) => $this->paramValue($route, $match[1]), $route->uri());
    }

    public function test_every_employee_route_declares_its_permission(): void
    {
        $missing = [];
        foreach ($this->employeeRoutes() as [$route, $middleware]) {
            if (!$this->abilities($middleware) && !in_array($route->getName(), self::PERMISSION_FREE, true)) {
                $missing[] = $this->label($route);
            }
        }

        $this->assertGreaterThan(70, count($this->employeeRoutes()), 'Too few employee routes: the route list did not load.');
        $this->assertSame([], $missing, "Employee routes without a permission (can:…):\n" . implode("\n", $missing));
    }

    public function test_a_role_with_nothing_ticked_gets_403_on_every_employee_route(): void
    {
        $this->actingAs($this->employeeUser([]), 'web');
        $wrong = [];
        foreach ($this->employeeRoutes() as [$route, $middleware]) {
            if (in_array($route->getName(), self::PERMISSION_FREE, true)) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($method) => $method !== 'HEAD');
            $status = $this->call($method, $this->uri($route), [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'])->getStatusCode();
            if ($status !== 403) {
                $wrong[] = $this->label($route) . ' → ' . $status;
            }
        }

        $this->assertSame([], $wrong, "Employee routes opened by a role with no permission:\n" . implode("\n", $wrong));
    }

    public function test_front_desk_passes_every_employee_route(): void
    {
        $user = $this->employeeUser();
        $refused = [];
        foreach ($this->employeeRoutes() as [$route, $middleware]) {
            foreach ($this->abilities($middleware) as $ability) {
                if (!$this->notFrontDesk($ability) && !Gate::forUser($user)->allows($ability)) {
                    $refused[] = $this->label($route) . ' → ' . $ability;
                }
            }
        }

        $this->assertSame([], $refused, "Front desk refused on (today every employee opens them):\n" . implode("\n", $refused));
    }

    public function test_front_desk_preset_is_the_migration_list(): void
    {
        $migration = require base_path('Modules/EmployeeModule/database/Migrations/2026_11_03_100030_add_role_id_to_employees_table.php');
        $this->assertSame($migration::FRONT_DESK, config('employeemodule.role_presets.front_desk.permissions'));
        // every key is in the catalog
        $catalog = array_merge(...array_values(array_map('array_keys', config('employeemodule.permissions'))));
        $this->assertSame([], array_values(array_diff($migration::FRONT_DESK, $catalog)));
    }

    // R1 (docs/roles-plan.md): the two "see all" keys + the two "any dates" keys — separate, never in Front desk, each brings
    // its view key with it
    public function test_cash_view_all_and_date_range_keys(): void
    {
        $catalog = array_merge(...array_values(array_map('array_keys', config('employeemodule.permissions'))));
        $frontDesk = config('employeemodule.role_presets.front_desk.permissions');
        $roles = app(\Modules\EmployeeModule\app\Services\RoleService::class);
        foreach (['payments.view_all' => 'payments.view', 'payments.date_range' => 'payments.view',
            'expenses.view_all' => 'expenses.view', 'expenses.date_range' => 'expenses.view'] as $key => $view) {
            $this->assertContains($key, $catalog);
            $this->assertNotContains($key, $frontDesk, "$key must not be in Front desk");
            $this->assertContains($view, $roles->normalize([$key]), "$key must tick $view");
        }
        $this->assertNotContains('expenses.view_all', $roles->normalize(['payments.view_all']), 'the two view_all keys are separate');
        $this->assertNotContains('payments.view_all', $roles->normalize(['expenses.view_all']), 'the two view_all keys are separate');
    }

    // HR (docs/hr-plan.md H1): the hr.* / documents.* keys are in the catalog, never in Front desk (a Front desk employee gets
    // 403 on every HR route), each brings hr.view with it
    public function test_hr_keys_are_not_in_front_desk(): void
    {
        $catalog = array_merge(...array_values(array_map('array_keys', config('employeemodule.permissions'))));
        $frontDesk = config('employeemodule.role_presets.front_desk.permissions');
        $roles = app(\Modules\EmployeeModule\app\Services\RoleService::class);
        foreach (['hr.view', 'hr.sensitive', 'hr.manage', 'documents.view', 'documents.manage', 'users.manage'] as $key) {
            $this->assertContains($key, $catalog);
            $this->assertNotContains($key, $frontDesk, "$key must not be in Front desk");
            $this->assertContains('hr.view', $roles->normalize([$key]), "$key must tick hr.view");
        }
        $this->assertContains('documents.view', $roles->normalize(['documents.manage']));
        $this->assertContains('hr.manage', $roles->normalize(['users.manage']));

        $this->actingAs($this->employeeUser(), 'web');
        $hrRoutes = 0;
        foreach ($this->employeeRoutes() as [$route, $middleware]) {
            if (!array_filter($this->abilities($middleware), fn ($ability) => $this->notFrontDesk($ability))) {
                continue;
            }
            $hrRoutes++;
            $method = collect($route->methods())->first(fn ($method) => $method !== 'HEAD');
            $status = $this->call($method, $this->uri($route), [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'])->getStatusCode();
            $this->assertSame(403, $status, 'Front desk opened ' . $this->label($route));
        }
        $this->assertGreaterThan(10, $hrRoutes, 'The HR employee routes did not load.');
    }

    // CashScope = the only rule for whose rows / which dates the Payments lists (and any export) show
    public function test_cash_scope_by_role(): void
    {
        $scope = app(\Modules\PaymentModule\app\Services\CashScope::class);
        $roles = app(\Modules\EmployeeModule\app\Services\RoleService::class);
        $own = 999003;

        $desk = $this->employeeUser();
        $this->assertSame([$own, $own, false, false], [$scope->paymentsCollector($desk), $scope->expensesCollector($desk), $scope->paymentsRange($desk), $scope->expensesRange($desk)], 'Front desk = own rows, one day');
        $this->assertFalse($scope->seesOthers('all', $desk));

        $cashIn = $this->employeeUser($roles->normalize(['payments.view_all', 'expenses.view']));
        $this->assertSame([null, $own, true, false], [$scope->paymentsCollector($cashIn), $scope->expensesCollector($cashIn), $scope->paymentsRange($cashIn), $scope->expensesRange($cashIn)], 'payments.view_all = every cash in, own cash out');
        $this->assertTrue($scope->rangeFor('in', $cashIn));
        $this->assertFalse($scope->rangeFor('out', $cashIn), 'Cash Out mixes expenses: one day without expenses any-dates');

        $cashOut = $this->employeeUser($roles->normalize(['expenses.view_all']));
        $this->assertSame([$own, null, false, true], [$scope->paymentsCollector($cashOut), $scope->expensesCollector($cashOut), $scope->paymentsRange($cashOut), $scope->expensesRange($cashOut)], 'expenses.view_all = every cash out, own cash in');

        $range = $this->employeeUser($roles->normalize(['payments.date_range', 'expenses.date_range']));
        $this->assertSame([$own, $own, true, true], [$scope->paymentsCollector($range), $scope->expensesCollector($range), $scope->paymentsRange($range), $scope->expensesRange($range)], 'date_range = own rows, any dates');
        $this->assertFalse($scope->seesOthers('in', $range));

        $both = $this->employeeUser($roles->normalize(['payments.view_all', 'expenses.view_all']));
        $this->assertTrue($scope->rangeFor('all', $both) && $scope->seesOthers('out', $both));

        $owner = (new User())->forceFill(['id' => 1]);
        $owner->setRelation('userable', (new Account())->forceFill(['id' => 999001, 'status' => Account::STATUS_ACTIVE]));
        $this->assertSame([null, null, true, true], [$scope->paymentsCollector($owner), $scope->expensesCollector($owner), $scope->paymentsRange($owner), $scope->expensesRange($owner)], 'the owner sees everything');
    }
}
