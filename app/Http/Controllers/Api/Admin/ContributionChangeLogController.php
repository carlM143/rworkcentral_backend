<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionChangeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContributionChangeLogController extends Controller
{
    private const RELATIONS = [
        'contributionType:id,contribution_code,contribution_name,provider,is_status',
        'rateSchedule:id,contribution_type_id,calculation_method,salary_basis,effective_from,effective_to,is_status',
        'company:id,company_name',
        'changedByUser:id,name,email',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = ContributionChangeLog::query()->with(self::RELATIONS);

        if ($request->filled('contribution_type_id')) {
            $query->where('contribution_type_id', $request->integer('contribution_type_id'));
        }

        if ($request->filled('contribution_rate_schedule_id')) {
            $query->where('contribution_rate_schedule_id', $request->integer('contribution_rate_schedule_id'));
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->string('entity_type')->trim()->toString());
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->trim()->toString());
        }

        if ($request->filled('changed_by')) {
            $query->where('changed_by', $request->integer('changed_by'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();

            $query->where(function ($builder) use ($search) {
                $builder->where('actor_name', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhereHas('contributionType', function ($typeQuery) use ($search) {
                        $typeQuery->where('contribution_code', 'like', "%{$search}%")
                            ->orWhere('contribution_name', 'like', "%{$search}%")
                            ->orWhere('provider', 'like', "%{$search}%");
                    })
                    ->orWhereHas('company', function ($companyQuery) use ($search) {
                        $companyQuery->where('company_name', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Contribution change logs retrieved successfully.',
            'data' => $query->orderByDesc('created_at')->orderByDesc('id')->get(),
        ]);
    }

    public function show(ContributionChangeLog $contributionChangeLog): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Contribution change log retrieved successfully.',
            'data' => $contributionChangeLog->load(self::RELATIONS),
        ]);
    }
}
