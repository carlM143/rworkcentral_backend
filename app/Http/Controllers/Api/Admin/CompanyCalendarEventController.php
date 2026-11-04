<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyCalendarEventController extends Controller
{
    public function store(Request $request)
    {
        return $this->save($request, new CompanyCalendarEvent());
    }

    public function update(Request $request, CompanyCalendarEvent $event)
    {
        abort_unless($event->company_id === Company::firstOrFail()->id, 404);
        return $this->save($request, $event);
    }

    private function save(Request $request, CompanyCalendarEvent $event)
    {
        $company = Company::firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'event_time' => ['nullable', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:5000'],
            'applies_to' => ['required', Rule::in(['all_locations', 'specific_locations'])],
            'location_ids' => ['required_if:applies_to,specific_locations', 'array'],
            'location_ids.*' => ['integer', 'distinct', Rule::exists('locations', 'id')->where('company_id', $company->id)],
        ]);
        $created = ! $event->exists;
        DB::transaction(function () use ($event, $data, $company, $request) {
            $event->fill(collect($data)->except('location_ids')->all());
            $event->company_id = $company->id;
            if (! $event->exists) $event->user_id = $request->user()->id;
            $event->save();
            $event->locations()->sync($data['applies_to'] === 'specific_locations' ? $data['location_ids'] : []);
        });
        return response()->json(['data' => $event->load('locations')], $created ? 201 : 200);
    }

    public function destroy(CompanyCalendarEvent $event)
    {
        abort_unless($event->company_id === Company::firstOrFail()->id, 404);
        $event->delete();
        return response()->json(['message' => 'Company event removed.']);
    }
}
