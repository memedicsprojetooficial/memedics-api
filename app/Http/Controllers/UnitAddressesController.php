<?php

namespace App\Http\Controllers;

use App\Models\UnitAddress;
use Illuminate\Http\Request;
use App\Http\Resources\UnitAddresses as UnitAddressesResources;
use App\Http\Resources\UnitAddressResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitAddressesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $unitAdresses = UnitAddress::query();

        if ($request->query('search')) {
            $unitAdresses->where('unit_name', 'like', sprintf('%s%%', $request->query('search')));
        }

        $unitAdresses->with('company')->orderBy('unit_name');

        return UnitAddressResource::collection($unitAdresses->cursorPaginate(25)->withQueryString());
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): UnitAddressResource
    {
        $input = $request->validate([
            'company_id'    => ['nullable', 'exists:companies,id'],
            'unit_name'     => ['required', 'string', 'max:255'],
            'street'        => ['nullable', 'string', 'max:255'],
            'number'        => ['nullable', 'string', 'max:20'],
            'complementary' => ['nullable', 'string', 'max:255'],
            'neighborhood'  => ['nullable', 'string', 'max:255'],
            'city'          => ['nullable', 'string', 'max:255'],
            'state'         => ['nullable', 'string', 'max:2'],
            'zip_code'      => ['nullable', 'string', 'max:9'],
            'confirmation_address' => ['nullable', 'string', 'max:500'],
        ]);

        $unitAddress = UnitAddress::create($input);

        return new UnitAddressResource($unitAddress);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\UnitAdresses  $unitAdresses
     * @return \Illuminate\Http\Response
     */
    public function show(UnitAddress $unitAddress): UnitAddressResource
    {
        $unitAddress->load('company', 'businessHours');

        return new UnitAddressResource($unitAddress);
    }


    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\UnitAdresses  $unitAdresses
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, UnitAddress $unitAddress): UnitAddressResource
    {
        $input = $request->validate([
            'company_id'    => ['sometimes', 'nullable', 'exists:companies,id'],
            'unit_name'     => ['sometimes', 'string', 'max:255'],
            'street'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'number'        => ['sometimes', 'nullable', 'string', 'max:20'],
            'complementary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'neighborhood'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'city'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'state'         => ['sometimes', 'nullable', 'string', 'max:2'],
            'zip_code'      => ['sometimes', 'nullable', 'string', 'max:9'],
            'confirmation_address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $unitAddress->update($input);

        return new UnitAddressResource($unitAddress);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\UnitAdresses  $unitAdresses
     * @return \Illuminate\Http\Response
     */
    public function destroy(UnitAddress $unitAddress): \Illuminate\Http\Response
    {
        $unitAddress->delete();

        return response()->noContent();
    }
}
