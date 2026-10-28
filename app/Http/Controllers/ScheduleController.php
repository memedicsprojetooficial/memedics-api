<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    /**
     * @param Request $request
     * @param Doctor $doctor
     * @return AnonymousResourceCollection
     */
    public function index(Request $request, Doctor $doctor): AnonymousResourceCollection
    {
        $query = $doctor->events()->with(EventResource::relations());

        $query->orderBy('date', 'DESC')->orderBy('id', 'DESC');

        if ($request->query('min_date')) {
            $query->where('date', '>=', $request->query('min_date'));
        }

        if ($request->query('max_date')) {
            $query->where('date', '<=', $request->query('max_date'));
        }

        return EventResource::collection($query->cursorPaginate(25)->withQueryString());
    }
}
