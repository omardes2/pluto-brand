<?php

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Hr\StoreEmployeeRequest;
use App\Http\Requests\Admin\Hr\UpdateEmployeeRequest;
use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * إدارة الموظفين (Production) — قائمة/إضافة/تعديل/حذف.
 * الصلاحيات عبر middleware `can:hr.employees.*` في المسارات. RTL + عربي.
 */
class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $service) {}

    public function index(Request $request): View
    {
        $employees = Employee::query()->with(['branch', 'user'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('job_title', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->string('status') === 'active'))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.employees.index', [
            'employees' => $employees,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('admin.employees.form', $this->formData(new Employee));
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return redirect()->route('admin.employees.index')->with('success', __('أُضيف الموظف.'));
    }

    public function edit(Employee $employee): View
    {
        return view('admin.employees.form', $this->formData($employee));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->service->update($employee, $request->validated());

        return redirect()->route('admin.employees.index')->with('success', __('حُدّث الموظف.'));
    }

    public function toggleActive(Employee $employee): RedirectResponse
    {
        $employee->update(['is_active' => ! $employee->is_active]);

        return back()->with('success', __('تم تحديث الحالة.'));
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return redirect()->route('admin.employees.index')->with('success', __('حُذف الموظف.'));
    }

    /** @return array<string, mixed> */
    private function formData(Employee $employee): array
    {
        return [
            'employee' => $employee,
            'branches' => Branch::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(['id', 'name', 'email']),
        ];
    }
}
