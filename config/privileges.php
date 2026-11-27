<?php

/*
|--------------------------------------------------------------------------
| Privileges (static)
|--------------------------------------------------------------------------
|
| Every privilege the HRIS checks, grouped by module and feature, in the
| order of the admin sidebar. Roles are dynamic (Settings › Users & Access ›
| Roles & Permissions); privileges are defined here, together with the code
| that checks them, and synced to the `privileges` table by PrivilegeSeeder.
|
| Each feature lists its actions. The stored privilege is:
|   module = the module key        (e.g. "payroll")
|   name   = "{feature}.{action}"  (e.g. "pay_periods.view")
| unless an action maps to an explicit name (kept for existing checks,
| e.g. timekeeping "view", "import", "correction.review").
|
| Actions shown as columns: view, create, edit, delete, approve, export.
| "notes" explains what an action means for that feature.
|
*/

return [

    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],

    'modules' => [

        'portal' => [
            'label' => 'Portal Access',
            'icon' => 'door',
            'features' => [
                'admin' => ['label' => 'Admin Portal', 'actions' => ['view' => 'admin'], 'notes' => 'Can sign in to the admin portal.'],
                'employee' => ['label' => 'Employee Portal', 'actions' => ['view' => 'employee'], 'notes' => 'Can sign in to the employee portal.'],
            ],
        ],

        // Employee portal (self-service). Checking any of these also gives the
        // role "Portal Access › Employee Portal" (Roles & Permissions page).
        'employee_portal' => [
            'label' => 'Employee Portal',
            'icon' => 'user-round',
            'portal' => 'employee',
            'features' => [
                'dashboard' => ['label' => 'Dashboard', 'actions' => ['view']],
                'attendance' => ['label' => 'Attendance', 'actions' => ['view'], 'notes' => 'Own time logs.'],
                'scan_qr' => ['label' => 'Scan QR', 'actions' => ['view', 'create'], 'notes' => 'Create = time in / out with the attendance QR code.'],
                'calendar' => ['label' => 'Calendar', 'actions' => ['view']],
                'leave_credits' => ['label' => 'Leave Credits', 'actions' => ['view']],
                'leave' => ['label' => 'Leave Requests', 'actions' => ['view', 'create'], 'notes' => 'Create = file, edit or cancel own requests.'],
                'undertime' => ['label' => 'Undertime Requests', 'actions' => ['view', 'create']],
                'overtime' => ['label' => 'Overtime Requests', 'actions' => ['view', 'create']],
                'time_log_corrections' => ['label' => 'Time Log Corrections', 'actions' => ['view', 'create']],
                'wfh' => ['label' => 'Work From Home', 'actions' => ['view', 'create']],
                'oba' => ['label' => 'Official Business (OBA)', 'actions' => ['view', 'create']],
                'payslips' => ['label' => 'Payslips', 'actions' => ['view', 'export'], 'notes' => 'Export = download or print.'],
                'loans' => ['label' => 'Loans', 'actions' => ['view']],
            ],
        ],

        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'dashboard',
            'features' => [
                'dashboard' => ['label' => 'Dashboard', 'actions' => ['view']],
            ],
        ],

        'employees' => [
            'label' => 'Employees',
            'icon' => 'users',
            'features' => [
                'directory' => ['label' => 'Employee Directory', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
                'salary_rates' => ['label' => 'Salary Rates', 'actions' => ['view', 'edit'], 'notes' => 'Salary amounts are sensitive.'],
                'salary_adjustments' => ['label' => 'Salary Adjustments', 'actions' => ['view', 'create', 'edit', 'delete', 'approve'], 'notes' => 'Approve = approve, reject and apply.'],
                'transfers' => ['label' => 'Transfer Department', 'actions' => ['view', 'create', 'approve']],
                'celebrations' => ['label' => 'Birthdays & Anniversaries', 'actions' => ['view', 'export']],
            ],
        ],

        'timekeeping' => [
            'label' => 'Timekeeping',
            'icon' => 'clock',
            'features' => [
                'time_logs' => [
                    'label' => 'Time Logs',
                    'actions' => ['view' => 'view', 'create' => 'import', 'export' => 'export'],
                    'notes' => 'Create = import from biometric devices.',
                ],
                'corrections' => [
                    'label' => 'Time Log Correction Requests',
                    'actions' => ['create' => 'correction.request', 'approve' => 'correction.review'],
                    'notes' => 'Create = file a correction, Approve = approve or reject.',
                ],
                'late_adjustments' => [
                    'label' => 'Late Adjustment',
                    'actions' => ['create' => 'adjustment.request', 'approve' => 'adjustment.review'],
                ],
                'manual_time' => ['label' => 'Manual Time In / Out', 'actions' => ['view', 'create']],
            ],
        ],

        'schedules' => [
            'label' => 'Schedules',
            'icon' => 'calendar-clock',
            'features' => [
                'work_schedules' => ['label' => 'Work Schedules', 'actions' => ['view', 'create', 'edit', 'delete']],
                'rest_day_switch' => ['label' => 'Switch Rest Day', 'actions' => ['view', 'approve']],
            ],
        ],

        'payroll' => [
            'label' => 'Payroll',
            'icon' => 'wallet',
            'features' => [
                'payroll_groups' => ['label' => 'Payroll Groups', 'actions' => ['view', 'create', 'edit', 'delete']],
                'pay_periods' => ['label' => 'Pay Periods', 'actions' => ['view', 'create', 'edit', 'delete']],
                'readiness' => ['label' => 'Payroll Readiness', 'actions' => ['view']],
                'employee_payroll' => [
                    'label' => 'Employee Payroll',
                    'actions' => ['view', 'create', 'approve', 'export'],
                    'notes' => 'Create = generate, Approve = finalize.',
                ],
                'history' => ['label' => 'Payroll History', 'actions' => ['view', 'export']],
                'payslips' => ['label' => 'Payslips', 'actions' => ['view', 'export']],
                'withholding_tax' => ['label' => 'Withholding Tax', 'actions' => ['view', 'edit'], 'notes' => 'BIR tax tables, MWE exemption and taxable allowances.'],
            ],
        ],

        'compensation' => [
            'label' => 'Compensation',
            'icon' => 'scale',
            'features' => [
                'wage_orders' => ['label' => 'Wage Orders', 'actions' => ['view', 'create', 'edit', 'delete', 'approve'], 'notes' => 'Approve = publish or cancel.'],
                'wage_adjustments' => ['label' => 'Wage Adjustments', 'actions' => ['view', 'create', 'approve'], 'notes' => 'Create = apply a wage order to employees, Approve = approve, reject and apply.'],
                'regional_rates' => ['label' => 'Regional Rates', 'actions' => ['view', 'edit']],
            ],
        ],

        'contributions' => [
            'label' => 'Contributions',
            'icon' => 'landmark',
            'features' => [
                'types' => ['label' => 'Contribution Types', 'actions' => ['view', 'create', 'edit', 'delete']],
                'rates' => ['label' => 'Contribution Rate', 'actions' => ['view', 'create', 'edit', 'delete']],
                'brackets' => ['label' => 'Bracket Management', 'actions' => ['view', 'create', 'edit', 'delete']],
                'history' => ['label' => 'Contribution History', 'actions' => ['view', 'export']],
                'settings' => ['label' => 'Contribution Settings', 'actions' => ['view', 'edit']],
            ],
        ],

        'loans' => [
            'label' => 'Loans',
            'icon' => 'hand-coins',
            'features' => [
                'types' => ['label' => 'Loan Types', 'actions' => ['view', 'create', 'edit', 'delete']],
                'employee_loans' => ['label' => 'Employee Loans', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'transactions' => ['label' => 'Loan Transactions', 'actions' => ['view', 'export']],
            ],
        ],

        'drivers' => [
            'label' => 'All Drivers',
            'icon' => 'truck',
            'features' => [
                'directory' => ['label' => 'Drivers Directory', 'actions' => ['view', 'create', 'edit']],
                'trip_tickets' => ['label' => 'Trip Tickets', 'actions' => ['view', 'create', 'edit', 'delete', 'export'], 'notes' => 'Delete = cancel a trip.'],
                'vehicles' => ['label' => 'Vehicles', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        'leave' => [
            'label' => 'Leave Management',
            'icon' => 'umbrella',
            'features' => [
                'balances' => ['label' => 'Leave Balances', 'actions' => ['view', 'edit']],
                'types' => ['label' => 'Leave Types', 'actions' => ['view', 'create', 'edit', 'delete']],
                'policies' => ['label' => 'Leave Policies', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        'holidays' => [
            'label' => 'Holiday Management',
            'icon' => 'calendar',
            'features' => [
                'holidays' => ['label' => 'Holiday', 'actions' => ['view', 'create', 'edit', 'delete']],
                'coverage' => ['label' => 'Holiday Coverage', 'actions' => ['view', 'edit']],
                'holiday_pay' => ['label' => 'Holiday Pay', 'actions' => ['view', 'edit']],
            ],
        ],

        'departments' => [
            'label' => 'Departments',
            'icon' => 'briefcase',
            'features' => [
                'departments' => ['label' => 'Departments', 'actions' => ['view', 'create', 'edit', 'delete']],
                'request_approvers' => ['label' => 'Request Approvers', 'actions' => ['view', 'edit'], 'notes' => 'View department approvers. Edit assigns or removes approvers.'],
                'positions' => ['label' => 'Positions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'employment_types' => ['label' => 'Employment Types', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        'approvals' => [
            'label' => 'Approval Center',
            'icon' => 'clipboard-check',
            'features' => [
                'first_level' => ['label' => 'For My Approval', 'actions' => ['view', 'approve'], 'notes' => 'View and approve requests assigned to you as a department approver.'],
                'department_override' => ['label' => 'Override Department Approval', 'actions' => ['approve'], 'notes' => 'Skip pending department approvals with a recorded reason. Final approval permission is also required.'],
                'leaves' => ['label' => 'Leave Approvals', 'actions' => ['view', 'approve'], 'notes' => 'Approve = final (HR) approval.'],
                'overtime' => ['label' => 'Overtime Approvals', 'actions' => ['view', 'approve'], 'notes' => 'Approve = final (HR) approval.'],
                'undertime' => ['label' => 'Undertime Approvals', 'actions' => ['view', 'approve'], 'notes' => 'Approve = final (HR) approval.'],
                'oba' => ['label' => 'OBA Approvals', 'actions' => ['view', 'approve'], 'notes' => 'Approve = final (HR) approval.'],
            ],
        ],

        'wfh' => [
            'label' => 'Work From Home',
            'icon' => 'house',
            'features' => [
                'requests' => ['label' => 'WFH Requests', 'actions' => ['view', 'create', 'approve']],
                'documents' => ['label' => 'WFH Document Review', 'actions' => ['view', 'approve']],
            ],
        ],

        'evaluation' => [
            'label' => 'Evaluation Templates',
            'icon' => 'clipboard',
            'features' => [
                'templates' => ['label' => 'Evaluation Templates', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        'lifecycle' => [
            'label' => 'Employee Lifecycle',
            'icon' => 'user-round',
            'features' => [
                'policies' => ['label' => 'Lifecycle Policies', 'actions' => ['view', 'create', 'edit', 'delete']],
                'stages' => ['label' => 'Lifecycle Stages', 'actions' => ['view', 'create', 'edit', 'delete']],
                'milestones' => ['label' => 'Employee Milestones', 'actions' => ['view', 'edit', 'approve']],
                'offboarding' => ['label' => 'Employee Offboarding', 'actions' => ['view', 'create', 'approve']],
                'history' => ['label' => 'Employee History', 'actions' => ['view', 'export']],
            ],
        ],

        'reports' => [
            'label' => 'Reports & Analytics',
            'icon' => 'chart',
            'features' => [
                'center' => ['label' => 'Reports Center', 'actions' => ['view']],
                'attendance' => ['label' => 'Attendance Reports', 'actions' => ['view', 'export']],
                'payroll' => ['label' => 'Payroll Reports', 'actions' => ['view', 'export']],
                'thirteenth_month' => ['label' => '13th Month Pay', 'actions' => ['view', 'export']],
                'government' => ['label' => 'SSS, Pag-IBIG & PhilHealth Reports', 'actions' => ['view', 'export']],
                'wage_compliance' => ['label' => 'Wage Compliance', 'actions' => ['view', 'export']],
                'withholding_tax' => ['label' => 'Withholding Tax Report', 'actions' => ['view', 'export']],
            ],
        ],

        'settings' => [
            'label' => 'Settings',
            'icon' => 'settings',
            'features' => [
                'company' => ['label' => 'Company Information', 'actions' => ['view', 'edit']],
                'users' => ['label' => 'System Users', 'actions' => ['view', 'create', 'edit']],
                'roles' => ['label' => 'Roles & Permissions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'qr' => ['label' => 'Attendance QR Code', 'actions' => ['view', 'create', 'delete']],
                'system' => ['label' => 'System Settings', 'actions' => ['view', 'edit']],
            ],
        ],
    ],

    /*
    | Default privileges per role, applied by PrivilegeSeeder only while a role
    | has no privileges outside the timekeeping module yet (so changes made in
    | Roles & Permissions are never overwritten). Administrator always gets
    | every privilege. Patterns: "module:name", "*" matches any part.
    */
    'defaults' => [
        'Human Resources' => [
            'portal:admin',
            'dashboard:*',
            'employees:*',
            'timekeeping:*',
            'schedules:*',
            'payroll:*.view',
            'payroll:*.export',
            'compensation:*',
            'contributions:*.view',
            'loans:*.view',
            'drivers:*',
            'leave:*',
            'holidays:*',
            'departments:*',
            'approvals:*',
            'wfh:*',
            'evaluation:*',
            'lifecycle:*',
            'reports:*',
            'settings:company.view',
            'settings:users.*',
            'settings:roles.view',
            'settings:qr.*',
        ],
        // Supervisors and managers sign in to the admin portal and see only
        'Manager' => [
            'portal:admin',
            'portal:employee',
            'employee_portal:*',
            'timekeeping:correction.request',
            'timekeeping:adjustment.request',
        ],
        'Supervisor' => [
            'portal:admin',
            'portal:employee',
            'employee_portal:*',
            'timekeeping:correction.request',
            'timekeeping:adjustment.request',
        ],
        'Employee' => [
            'portal:employee',
            'employee_portal:*',
            'timekeeping:correction.request',
            'timekeeping:adjustment.request',
        ],
    ],
];
