<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CheckoutOrderBump;
use Illuminate\Http\Request;

class OrderbumpController extends Controller
{
    public function create($id, Request $request)
    {
        $data = $request->except(['_token', '_method']);
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                unset($data[$key]);
            }
        }
        $data['active'] = $data['active'] == 'on' ? true : false;
        $data['valor_de'] = (float) $data['valor_de'];
        $data['valor_por'] = (float) $data['valor_por'];
        $data['checkout_id'] = $id;
        //dd($data);
        $orderbumpDir = public_path("/checkouts/{$id}/orderbumps/");
        if (!file_exists($orderbumpDir)) {
            mkdir($orderbumpDir, 0755, true);
        }

        if ($request->hasFile('image')) {
            $filename = 'orderbump_image_' . '.' . $request->file('image')->getClientOriginalExtension();
            $request->file('image')->move($orderbumpDir, $filename);
            $data['image'] = "/checkouts/{$id}/orderbumps/{$filename}";
        }

        CheckoutOrderBump::create($data);

        return redirect()->to(url()->previous() . '#orderbumps')->with('success', 'Order bump cadastrado com sucesso!');
    }

    public function edit($id, Request $request)
    {
        $data = $request->except(['_token', '_method']);
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                unset($data[$key]);
            }
        }
        $data['active'] = $data['active'] == 'on' ? true : false;
        $data['valor_de'] = (float) $data['valor_de'];
        $data['valor_por'] = (float) $data['valor_por'];

        $orderbump = CheckoutOrderBump::where('id', $id)->first();

        $orderbumpDir = public_path("/checkouts/{$orderbump->checkout_id}/orderbumps/");
        if (!file_exists($orderbumpDir)) {
            mkdir($orderbumpDir, 0755, true);
        }

        if ($request->hasFile('image')) {
            $filename = 'orderbump_image_' . '.' . $request->file('image')->getClientOriginalExtension();
            $request->file('image')->move($orderbumpDir, $filename);
            $data['image'] = "/checkouts/{$orderbump->checkout_id}/orderbumps/{$filename}";
        }

        $orderbump->update($data);
        return redirect()->to(url()->previous() . '#orderbumps')->with('success', 'Order bump alterado com sucesso!');
    }

    public function removeBump($id, Request $request)
    {
        CheckoutOrderBump::where('id', $id)->first()->delete();
        return redirect()->to(url()->previous() . '#orderbumps')->with('success', 'Order bump removido com sucesso!');
    }
}
