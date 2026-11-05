{{-- a salary history row: Edit, New salary from it (a raise pre-filled), Delete --}}
@include('layoutmodule::partials.row-menu', [
    'links' => [
        ['label' => 'Edit', 'icon' => 'pencil', 'url' => route($area . '.payroll.salaries.edit', [$staff->id, $salary->id]), 'modal' => true],
        ['label' => 'New Salary from This', 'icon' => 'copy', 'url' => route($area . '.payroll.salaries.create', [$staff->id, 'from' => $salary->id]), 'modal' => true],
    ],
    'actions' => [['view' => 'payrollmodule::Salary.partials.delete-form', 'data' => ['staff' => $staff, 'salary' => $salary, 'area' => $area, 'gap' => $gap ?? null]]],
])
