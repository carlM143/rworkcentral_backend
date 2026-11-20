<?php

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
                    'label' => 'Time Log Corrections',
                    'actions' => ['create' => 'correction.request', 'approve' => 'correction.review'],
                    'notes' => 'Create = file a correction, Approve = approve or reject.',
                ],
                'late_adjustments' => [
                    'label' => 'Late Adjustments',
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
                'rates' => ['label' => 'Contribution Rates', 'actions' => ['view', 'create', 'edit', 'delete']],
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
                'holidays' => ['label' => 'Holidays', 'actions' => ['view', 'create', 'edit', 'delete']],
                'coverage' => ['label' => 'Holiday Coverage', 'actions' => ['view', 'edit']],
                'holiday_pay' => ['label' => 'Holiday Pay', 'actions' => ['view', 'edit']],
            ],
        ],

        'departments' => [
            'label' => 'Departments',
            'icon' => 'briefcase',
            'features' => [
                'departments' => ['label' => 'Departments', 'actions' => ['view', 'create', 'edit', 'delete']],
                'positions' => ['label' => 'Positions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'employment_types' => ['label' => 'Employment Types', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        'approvals' => [
            'label' => 'Approval Center',
            'icon' => 'clipboard-check',
            'features' => [
                'leaves' => ['label' => 'Leave Approvals', 'actions' => ['view', 'approve']],
                'overtime' => ['label' => 'Overtime Approvals', 'actions' => ['view', 'approve']],
                'undertime' => ['label' => 'Undertime Approvals', 'actions' => ['view', 'approve']],
                'oba' => ['label' => 'OBA Approvals', 'actions' => ['view', 'approve']],
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
                'government' => ['label' => 'SSS, Pag-IBIG & PhilHealth', 'actions' => ['view', 'export']],
                'wage_compliance' => ['label' => 'Wage Compliance', 'actions' => ['view', 'export']],
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
        'Manager' => [
            'portal:employee',
            'timekeeping:view',
            'timekeeping:correction.request',
            'timekeeping:adjustment.request',
            'approvals:*',
        ],
        'Supervisor' => [
            'portal:employee',
            'timekeeping:view',
            'timekeeping:correction.request',
            'timekeeping:adjustment.request',
            'approvals:*',
        ],
        'Employee' => [
            'portal:employee',
            'timekeeping:correction.request',
            'timekeeping:adjustment.request',
        ],
    ],
];
